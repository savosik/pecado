<?php

namespace App\Services\Pricing;

use App\Models\IndividualPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Обёртка для обращений к prices DB с graceful degradation.
 * При недоступности mysql-prices — возвращает null/пустую коллекцию
 * вместо исключения, позволяя сайту работать на базовых ценах.
 */
class IndividualPriceProxy
{
    /**
     * Получить индивидуальную цену партнёра по товару (и складу).
     * При недоступности prices DB — возвращает null (базовая цена).
     */
    public static function findPrice(int $partnerId, int $productId, ?int $warehouseId = null): ?IndividualPrice
    {
        try {
            $query = IndividualPrice::where('partner_id', $partnerId)
                ->where('product_id', $productId);

            if ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            }

            return $query->first();
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('IndividualPriceProxy: prices DB недоступна', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Загрузить карту индивидуальных цен [product_id => price] для партнёра.
     * При недоступности prices DB — возвращает пустую коллекцию (базовые цены).
     *
     * @param  int[]  $productIds
     * @return Collection<int, float>
     */
    public static function loadPriceMap(int $partnerId, array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        try {
            return DB::connection('prices')
                ->table('individual_prices')
                ->where('partner_id', $partnerId)
                ->whereIn('product_id', $productIds)
                ->select('product_id', 'price')
                ->get()
                ->mapWithKeys(fn ($row) => [(int) $row->product_id => (float) $row->price]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('IndividualPriceProxy: prices DB недоступна при загрузке карты цен', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }
}
