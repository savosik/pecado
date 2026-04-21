<?php

namespace App\Services\Erp\Handlers;

use App\Models\ErpPromotion;
use Illuminate\Support\Facades\DB;

/**
 * Пересчёт агрегированных флагов is_new / is_bestseller / is_liquidation на товарах
 * по привязкам в `erp_promotion_product`. Флаг = true, если товар состоит хотя бы
 * в одной ErpPromotion соответствующего type.
 */
class RecalculateProductPromoFlags
{
    /**
     * @param  int[]  $productIds
     * @param  string[]  $types  Ограничить пересчёт указанными типами. Если пусто — все три.
     */
    public static function forProducts(array $productIds, array $types = []): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (empty($productIds)) {
            return;
        }

        $types = array_values(array_intersect(
            empty($types) ? ErpPromotion::TYPES : $types,
            ErpPromotion::TYPES,
        ));

        foreach ($types as $type) {
            $column = ErpPromotion::PRODUCT_FLAG_MAP[$type];

            $active = DB::table('erp_promotion_product')
                ->join('erp_promotions', 'erp_promotions.id', '=', 'erp_promotion_product.erp_promotion_id')
                ->where('erp_promotions.type', $type)
                ->whereIn('erp_promotion_product.product_id', $productIds)
                ->pluck('erp_promotion_product.product_id')
                ->unique()
                ->all();

            if (! empty($active)) {
                DB::table('products')
                    ->whereIn('id', $active)
                    ->where($column, false)
                    ->update([$column => true, 'updated_at' => now()]);
            }

            $inactive = array_values(array_diff($productIds, $active));
            if (! empty($inactive)) {
                DB::table('products')
                    ->whereIn('id', $inactive)
                    ->where($column, true)
                    ->update([$column => false, 'updated_at' => now()]);
            }
        }
    }
}
