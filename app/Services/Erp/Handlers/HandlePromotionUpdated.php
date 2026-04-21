<?php

namespace App\Services\Erp\Handlers;

use App\Models\ErpPromotion;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события promotion.updated из 1С.
 * Полная замена состава товаров промо-группы, смена type допускается.
 * При отсутствии ErpPromotion c данным uuid — создаётся (ведёт себя как upsert).
 */
class HandlePromotionUpdated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $type = $payload['type'] ?? null;
        $items = $payload['items'] ?? [];

        if (! $uuid || ! $type) {
            Log::warning('promotion.updated: отсутствуют обязательные поля uuid/type', [
                'payload' => $payload,
            ]);

            return;
        }

        if (! in_array($type, ErpPromotion::TYPES, true)) {
            Log::warning('promotion.updated: неизвестный type', [
                'uuid' => $uuid,
                'type' => $type,
            ]);

            return;
        }

        DB::transaction(function () use ($uuid, $type, $items) {
            $promotion = ErpPromotion::where('uuid', $uuid)->first();
            $previousType = $promotion?->type;
            $previousProductIds = $promotion
                ? $promotion->products()->pluck('products.id')->all()
                : [];

            $promotion = ErpPromotion::updateOrCreate(
                ['uuid' => $uuid],
                ['type' => $type],
            );

            $productUuids = collect($items)
                ->pluck('uuid')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $productIds = Product::withoutGlobalScopes()
                ->whereIn('external_id', $productUuids)
                ->pluck('id')
                ->all();

            $missing = count($productUuids) - count($productIds);
            if ($missing > 0) {
                Log::info('promotion.updated: часть товаров не найдена по external_id', [
                    'uuid' => $uuid,
                    'missing' => $missing,
                ]);
            }

            $promotion->products()->sync($productIds);

            $affected = array_unique(array_merge($previousProductIds, $productIds));
            $types = array_unique(array_filter([$previousType, $type]));
            RecalculateProductPromoFlags::forProducts($affected, $types);

            Log::info('promotion.updated: обработано', [
                'uuid' => $uuid,
                'type' => $type,
                'products' => count($productIds),
                'type_changed' => $previousType && $previousType !== $type,
            ]);
        });
    }
}
