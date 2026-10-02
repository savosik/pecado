<?php

namespace App\Services\Crm\Mail\Sources;

use App\Models\PrintedDocument;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

/**
 * Повод для письма о печатной форме.
 *
 * Две ловушки, из-за которых повод нельзя ставить в обработчике сообщения 1С:
 *
 * 1. Документ приезжает без `company_id` — привязку доклеивает `documents:relink`
 *    раз в десять минут. Сигнал, поставленный раньше, промахнётся мимо правил
 *    по контрагенту, а это ровно тот кейс, ради которого домен и заводился.
 * 2. Файл переносится отдельным job-ом. Письмо со ссылкой на неперенесённый
 *    файл бесполезно: кабинет отдаст 404.
 *
 * Поэтому точка одна — момент, когда файл сохранён и контрагент известен.
 * Если контрагента ещё нет, повод не ставится: его выставит relink.
 */
class DocumentOccasions
{
    public function __construct(private readonly MailStream $stream) {}

    public function published(PrintedDocument $document): void
    {
        if (! $this->isReady($document) || $this->isTooOld($document) || $this->isExtraFormat($document)) {
            return;
        }

        $this->stream->captureQuietly(new Occasion(
            key: 'documents.published',
            clientUserId: $document->user_id,
            companyId: $document->company_id,
            subject: $document,
            data: [
                'document_type' => $document->type?->value,
                'document_number' => $document->number,
                'document_date' => $document->date?->toDateString(),
                'organization_id' => $document->organization_id,
                'is_revision' => (int) ($document->revision ?? 0) > 1,
                'base_document_kind' => $document->base_document_kind,
                'document_title' => $this->title($document),
            ],
            view: [
                'title' => $this->title($document),
                'body' => $this->body($document),
                'url' => url(route('cabinet.documents.index', [], false)),
                'entity_label' => $this->title($document),
            ],
            // Ценз потока (`mail_stream.max_age_minutes`) считается от появления
            // документа в системе: он отсекает запоздавшую обработку. Давность
            // самого документа проверена выше, в isTooOld().
            occurredAt: $document->created_at,
        ));
    }

    /**
     * Документ отозван.
     *
     * Письмо внутреннее: его получает только персональный менеджер партнёра
     * (решение заказчика 02.10.2026), адресат закреплён в `config/mail_occasions.php`.
     * Зовётся из обработчика `printed_document.deleted` сразу после мягкого удаления —
     * ловушки публикации (файл ещё не перенесён, контрагент не доклеен) здесь
     * не действуют: отзывать форму, которую клиент не видел, не о чем писать.
     *
     * Срез по давности тот же, что у публикации (решение заказчика 02.10.2026):
     * отзыв январской формы — такая же не-новость, как её появление. В кабинете
     * документ отзывается как обычно, срез касается только письма.
     */
    public function deleted(PrintedDocument $document): void
    {
        // Без партнёра нет и его менеджера — писать некому.
        if ($document->user_id === null || ! $this->isReady($document) || $this->isTooOld($document)) {
            return;
        }

        $this->stream->captureQuietly(new Occasion(
            key: 'documents.deleted',
            clientUserId: $document->user_id,
            companyId: $document->company_id,
            subject: $document,
            data: [
                'document_type' => $document->type?->value,
                'document_number' => $document->number,
                'document_title' => $this->title($document),
            ],
            view: [
                'title' => 'Документ отозван: '.$this->title($document),
                'body' => 'Учётная система отозвала документ, ранее выложенный в личный кабинет партнёра. Клиенту письмо об этом не уходит: если он успел скачать документ, его копия больше не актуальна — при необходимости предупредите его сами.',
            ],
        ));
    }

    /**
     * Второй файл той же формы — не новость для клиента.
     *
     * 1С выгружает УПД дважды: PDF для подписи и XLSX для загрузки в учётную
     * систему. Это разные сообщения с разными `uuid`, но один документ: в кабинете
     * он одной строкой с двумя кнопками, и письмо о нём тоже должно быть одно.
     *
     * Перевыставление (`revision` больше первого) под это правило не подпадает:
     * там клиенту действительно есть что сказать, а два письма подряд склеит
     * окно `mail_stream.batch_seconds`.
     */
    private function isExtraFormat(PrintedDocument $document): bool
    {
        if ($document->variant_key === null || (int) ($document->revision ?? 0) > 1) {
            return false;
        }

        return PrintedDocument::query()
            ->where('variant_key', $document->variant_key)
            ->whereKeyNot($document->getKey())
            ->where('id', '<', $document->getKey())
            ->stored()
            ->exists();
    }

    /**
     * Документ слишком старый, чтобы быть новостью.
     *
     * 1С догружает историю пачками: форма появляется на сайте сегодня, но сам
     * документ — январский. В кабинете ему место, в почте — нет (решение
     * заказчика 01.10.2026; с 02.10.2026 — и для отзыва). Срез стоит здесь, у источника события, а не
     * в матрице уведомлений: «кому слать» остаётся свойством партнёра, а
     * «новость ли это» решает домен документов.
     *
     * Меряется дата самого документа, не `created_at`. Формы без даты
     * уведомляют как раньше: молчать о документе из-за пустого поля хуже,
     * чем написать о старом. Документ возрастом ровно в порог — ещё новость.
     */
    private function isTooOld(PrintedDocument $document): bool
    {
        $maxAgeDays = (int) config('documents.notify_max_age_days', 31);

        if ($maxAgeDays <= 0 || $document->date === null) {
            return false;
        }

        return $document->date->copy()->startOfDay()->lessThan(today()->subDays($maxAgeDays));
    }

    /**
     * Готов ли документ к рассылке: файл на месте и контрагент известен.
     *
     * Формы внутренних юрлиц («Реклама») клиенту не показываются, значит и
     * письмо «появился документ» о них — ссылка в пустоту.
     */
    private function isReady(PrintedDocument $document): bool
    {
        return $document->file_status === PrintedDocument::FILE_STORED
            && $document->company_id !== null
            && ! $document->isFromInternalOrganization();
    }

    private function title(PrintedDocument $document): string
    {
        $type = $document->type?->label() ?: 'Документ';
        $number = $document->number ? ' № '.$document->number : '';
        $date = $document->date ? ' от '.$document->date->format('d.m.Y') : '';

        return $type.$number.$date;
    }

    private function body(PrintedDocument $document): string
    {
        return sprintf(
            'В личном кабинете появился документ: %s. Скачать его можно в разделе «Документы».',
            $this->title($document),
        );
    }
}
