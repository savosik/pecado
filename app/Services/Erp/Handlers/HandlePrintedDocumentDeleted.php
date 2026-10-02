<?php

namespace App\Services\Erp\Handlers;

use App\Models\PrintedDocument;
use App\Services\Crm\Mail\Sources\DocumentOccasions;
use Illuminate\Support\Facades\Log;

/**
 * Отзыв печатной формы: документ помечен на удаление или отменён в 1С (v16.1.0).
 *
 * Удаление мягкое, и файл на диске остаётся. Снятие пометки удаления в 1С —
 * обычная операция, а перезалить PDF заново неоткуда: печатные формы там
 * не хранятся. Физически файл сносит команда `documents:prune` по своей ретенции.
 *
 * Об отзыве узнаёт персональный менеджер партнёра (`documents.deleted`, решение
 * заказчика 02.10.2026): клиент мог успеть скачать форму, и предупредить его —
 * дело менеджера. Клиенту письмо не уходит. Повторная доставка сообщения
 * второго письма не даёт: уже отозванная форма сюда не доходит.
 */
class HandlePrintedDocumentDeleted
{
    protected string $event = 'printed_document.deleted';

    public function __construct(private readonly DocumentOccasions $occasions) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            Log::warning($this->event.': отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $uuid = trim($uuid);
        $document = PrintedDocument::withTrashed()->where('uuid', $uuid)->first();

        if (! $document) {
            // Отзыв формы, которой у нас нет: 1С удалила документ раньше, чем
            // выгрузила его, либо сообщение о публикации потерялось. Создавать
            // запись-надгробие незачем — показывать в ней нечего.
            Log::info($this->event.': печатная форма не найдена, событие проигнорировано', [
                'uuid' => $uuid,
            ]);

            return;
        }

        if ($document->trashed()) {
            Log::info($this->event.': форма уже отозвана', ['uuid' => $uuid]);

            return;
        }

        $document->delete();

        Log::info($this->event.': печатная форма отозвана, файл сохранён на диске', [
            'printed_document_id' => $document->id,
            'uuid' => $uuid,
            'reason' => $payload['reason'] ?? null,
        ]);

        // После удаления и без права его уронить: письмо менеджеру вторично,
        // отзыв формы — нет (внутри captureQuietly).
        $this->occasions->deleted($document);
    }
}
