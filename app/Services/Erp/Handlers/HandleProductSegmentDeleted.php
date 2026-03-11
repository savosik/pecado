<?php

namespace App\Services\Erp\Handlers;

use App\Models\ProductSegment;
use Illuminate\Support\Facades\Log;

/**
 * US-11: Обработка события product_segment.deleted из 1С.
 * Удаляет сегмент номенклатуры; связи через pivot-таблицу удаляются каскадно.
 */
class HandleProductSegmentDeleted
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('product_segment.deleted: отсутствует поле uuid', [
                'payload' => $payload,
            ]);
            return;
        }

        $deleted = ProductSegment::where('uuid', $uuid)->delete();

        if ($deleted) {
            Log::info('product_segment.deleted: сегмент удалён', ['uuid' => $uuid]);
        } else {
            Log::info('product_segment.deleted: сегмент не найден (уже удалён или не существовал)', ['uuid' => $uuid]);
        }
    }
}
