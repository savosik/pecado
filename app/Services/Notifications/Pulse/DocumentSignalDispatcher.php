<?php

namespace App\Services\Notifications\Pulse;

use App\Models\PrintedDocument;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Support\Notifications\SignalBus;

/**
 * Сигнал пульту о печатной форме.
 *
 * Две ловушки, из-за которых сигнал нельзя ставить в обработчике сообщения 1С:
 *
 * 1. Документ приезжает без `company_id` — привязку доклеивает `documents:relink`
 *    раз в десять минут. Сигнал, поставленный раньше, промахнётся мимо правил
 *    по контрагенту, а это ровно тот кейс, ради которого домен и заводился.
 * 2. Файл переносится отдельным job-ом. Письмо со ссылкой на неперенесённый
 *    файл бесполезно: кабинет отдаст 404.
 *
 * Поэтому точка одна — момент, когда файл сохранён и контрагент известен.
 * Если контрагента ещё нет, сигнал не ставится: его выставит relink.
 */
class DocumentSignalDispatcher
{
    public function __construct(private readonly SignalBus $bus) {}

    public function published(PrintedDocument $document): void
    {
        if (! $this->isReady($document)) {
            return;
        }

        $this->bus->publish(new PulseSignal(
            eventKey: 'documents.published',
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
            // Возрастной ценз считается от даты появления документа в системе,
            // а не от даты самого документа: акт сверки за прошлый год —
            // свежая новость, если 1С выложила его сегодня.
            occurredAt: $document->created_at,
        ));
    }

    public function deleted(PrintedDocument $document): void
    {
        if ($document->company_id === null) {
            return;
        }

        $this->bus->publish(new PulseSignal(
            eventKey: 'documents.deleted',
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
                'body' => 'Учётная система отозвала ранее выложенный документ. Если вы уже сохранили его копию, она больше не актуальна.',
            ],
        ));
    }

    /**
     * Готов ли документ к рассылке: файл на месте и контрагент известен.
     */
    private function isReady(PrintedDocument $document): bool
    {
        return $document->file_status === PrintedDocument::FILE_STORED
            && $document->company_id !== null;
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
