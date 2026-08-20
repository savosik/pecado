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
     *
     * С указанным складом — строгий фильтр по нему. Без склада, когда у товара
     * есть цены на нескольких складах, — детерминированное правило «минимальный
     * warehouse_id»: то же, что в loadPriceMap. Иначе карточка товара и каталог
     * показывали клиенту разные цены на один товар.
     */
    public static function findPrice(int $partnerId, int $productId, ?int $warehouseId = null): ?IndividualPrice
    {
        try {
            $query = IndividualPrice::where('partner_id', $partnerId)
                ->where('product_id', $productId);

            if ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            } else {
                $query->orderBy('warehouse_id');
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
     * Проверить наличие хотя бы одной индивидуальной цены у партнёра.
     * При недоступности prices DB — возвращает true, чтобы не показывать
     * ложное предупреждение об отсутствии цен из-за временного сбоя.
     */
    public static function hasAnyForPartner(int $partnerId): bool
    {
        try {
            return IndividualPrice::where('partner_id', $partnerId)->exists();
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('IndividualPriceProxy: prices DB недоступна при проверке наличия цен', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Загрузить карту индивидуальных цен [product_id => price] для партнёра.
     * При недоступности prices DB — возвращает пустую коллекцию (базовые цены).
     *
     * Цены приходят из 1С в разрезе складов, поэтому на один товар их может быть
     * несколько. С указанным складом — строгий фильтр по нему (нет строки → товара
     * нет в карте, действует базовая цена). Без склада — «минимальный warehouse_id»,
     * то же правило, что в findPrice: раньше mapWithKeys оставлял произвольную
     * строку набора, и каталог мог показать не ту цену, что карточка товара.
     *
     * @param  int[]  $productIds
     * @return Collection<int, float>
     */
    public static function loadPriceMap(int $partnerId, array $productIds, ?int $warehouseId = null): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        try {
            $query = DB::connection('prices')
                ->table('individual_prices')
                ->where('partner_id', $partnerId)
                ->whereIn('product_id', $productIds);

            if ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            }

            $rows = $query->select('product_id', 'warehouse_id', 'price')->get();

            $chosen = [];
            $chosenWarehouse = [];

            foreach ($rows as $row) {
                $productId = (int) $row->product_id;
                $rowWarehouseId = (int) $row->warehouse_id;

                if (! isset($chosenWarehouse[$productId]) || $rowWarehouseId < $chosenWarehouse[$productId]) {
                    $chosenWarehouse[$productId] = $rowWarehouseId;
                    $chosen[$productId] = (float) $row->price;
                }
            }

            return collect($chosen);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('IndividualPriceProxy: prices DB недоступна при загрузке карты цен', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }
}
