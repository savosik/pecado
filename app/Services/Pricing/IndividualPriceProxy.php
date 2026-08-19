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
     * С указанным складом — строгий фильтр: нет строки по складу → null (базовая).
     * Без склада — детерминированное правило «минимальный warehouse_id»: то же,
     * что в loadPriceMap, иначе карточка и каталог показывали бы разные цены
     * при нескольких складских ценах на товар.
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
     * Выбор строки при нескольких складских ценах на товар:
     *  - в $warehouseMap задан склад-победитель стопки — берётся строго его цена,
     *    нет строки по складу → товара нет в карте (базовая цена);
     *  - склада нет (null / регион без стопки) — детерминированное правило
     *    «минимальный warehouse_id», то же, что в findPrice без склада.
     *
     * @param  int[]  $productIds
     * @param  array<int, int|null>|null  $warehouseMap  product_id → warehouse_id победителя стопки
     * @return Collection<int, float>
     */
    public static function loadPriceMap(int $partnerId, array $productIds, ?array $warehouseMap = null): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        try {
            $rows = DB::connection('prices')
                ->table('individual_prices')
                ->where('partner_id', $partnerId)
                ->whereIn('product_id', $productIds)
                ->select('product_id', 'warehouse_id', 'price')
                ->get();

            $chosen = [];
            $chosenWarehouse = [];

            foreach ($rows as $row) {
                $productId = (int) $row->product_id;
                $warehouseId = (int) $row->warehouse_id;
                $target = $warehouseMap[$productId] ?? null;

                if ($target !== null) {
                    if ($warehouseId === (int) $target) {
                        $chosen[$productId] = (float) $row->price;
                    }
                } elseif (! isset($chosenWarehouse[$productId]) || $warehouseId < $chosenWarehouse[$productId]) {
                    $chosenWarehouse[$productId] = $warehouseId;
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
