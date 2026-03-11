<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\ProductSegment;
use Illuminate\Support\Facades\Log;

/**
 * US-11: Обработка события product_segment.created из 1С.
 * Создаёт или обновляет сегмент номенклатуры и его состав (товары).
 * Идемпотентен: повторная обработка обновляет существующий сегмент.
 */
class HandleProductSegmentCreated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $name = $payload['name'] ?? null;
        $productUuids = $payload['product_uuids'] ?? [];

        if (!$uuid || !$name) {
            Log::warning('product_segment.created: отсутствуют обязательные поля uuid или name', [
                'payload' => $payload,
            ]);
            return;
        }

        // Идемпотентный upsert сегмента
        $segment = ProductSegment::updateOrCreate(
            ['uuid' => $uuid],
            ['name' => $name]
        );

        // Синхронизация товаров по external_id
        $productIds = Product::whereIn('external_id', $productUuids)
            ->pluck('id');

        $segment->products()->sync($productIds);

        Log::info('product_segment.created: сегмент создан/обновлён', [
            'uuid' => $uuid,
            'name' => $name,
            'products_count' => $productIds->count(),
            'unknown_uuids' => count($productUuids) - $productIds->count(),
        ]);
    }
}
