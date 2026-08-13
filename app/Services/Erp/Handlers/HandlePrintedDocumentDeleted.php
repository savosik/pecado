<?php

namespace App\Services\Erp\Handlers;

use App\Models\PrintedDocument;
use Illuminate\Support\Facades\Log;

/**
 * Отзыв печатной формы: документ помечен на удаление или отменён в 1С (v16.1.0).
 *
 * Удаление мягкое, и файл на диске остаётся. Снятие пометки удаления в 1С —
 * обычная операция, а перезалить PDF заново неоткуда: печатные формы там
 * не хранятся. Физически файл сносит команда `documents:prune` по своей ретенции.
 */
class HandlePrintedDocumentDeleted
{
    protected string $event = 'printed_document.deleted';

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
    }
}
