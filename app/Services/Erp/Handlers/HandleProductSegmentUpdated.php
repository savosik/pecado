<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\ProductSegment;
use Illuminate\Support\Facades\Log;

/**
 * US-11: Обработка события product_segment.updated из 1С.
 * Обновляет существующий сегмент номенклатуры и его состав (товары).
 * Идемпотентен: поведение аналогично product_segment.created.
 */
class HandleProductSegmentUpdated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $name = $payload['name'] ?? null;
        $productUuids = $payload['product_uuids'] ?? [];

        if (!$uuid || !$name) {
            Log::warning('product_segment.updated: отсутствуют обязательные поля uuid или name', [
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

        Log::info('product_segment.updated: сегмент обновлён', [
            'uuid' => $uuid,
            'name' => $name,
            'products_count' => $productIds->count(),
        ]);
    }
}
