<?php

namespace App\Services\Erp\Handlers;

use App\Models\ErpPromotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события promotion.deleted из 1С.
 * Удаляет ErpPromotion и все его привязки к товарам; у освободившихся товаров
 * снимается соответствующий флаг, если они не состоят в других промо того же type.
 */
class HandlePromotionDeleted
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('promotion.deleted: отсутствует обязательное поле uuid', [
                'payload' => $payload,
            ]);

            return;
        }

        $promotion = ErpPromotion::where('uuid', $uuid)->first();

        if (! $promotion) {
            Log::info('promotion.deleted: промо-группа не найдена — игнорируем', [
                'uuid' => $uuid,
            ]);

            return;
        }

        DB::transaction(function () use ($promotion, $uuid) {
            $type = $promotion->type;
            $productIds = $promotion->products()->pluck('products.id')->all();

            $promotion->products()->detach();
            $promotion->delete();

            RecalculateProductPromoFlags::forProducts($productIds, [$type]);

            Log::info('promotion.deleted: обработано', [
                'uuid' => $uuid,
                'type' => $type,
                'products' => count($productIds),
            ]);
        });
    }
}
