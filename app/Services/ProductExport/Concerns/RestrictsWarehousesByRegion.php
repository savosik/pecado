<?php

namespace App\Services\ProductExport\Concerns;

use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ограничивает eager-load связь `warehouses` складами, которые доступны
 * региону клиента выгрузки (таблица region_warehouse, оба типа —
 * primary и preorder).
 *
 * Why: складские поля выгрузки (WarehouseQuantityField,
 * WarehousesQuantityField, WarehousesNameField, TotalStockField) читают
 * $product->warehouses напрямую, без какой-либо фильтрации по региону.
 * В отличие от UserStock*Field, которые ходят через StockService и режут
 * остатки по region_warehouse, эти поля отдавали клиенту остатки складов
 * вне его региона (например «Москва персональный»), даже если регион
 * клиента доступ к такому складу не даёт.
 *
 * Партнёры, настроившие выгрузку с колонкой такого склада ДО введения
 * региональных ограничений, продолжали получать по ней остатки. Этот трейт
 * закрывает лазейку в одной точке: ограничиваем сам набор складов, который
 * грузится в чанк, — тогда все четыре поля автоматически перестают видеть
 * недоступные склады.
 *
 * Ограничение применяется только когда у выгрузки есть клиент
 * (client_user_id). Админская выгрузка без клиента (client_user_id = null)
 * — внутренний инструмент и складами не режется.
 */
trait RestrictsWarehousesByRegion
{
    /**
     * Преобразовать список eager-load связей так, чтобы связь `warehouses`
     * грузилась только для складов, доступных региону клиента.
     *
     * @param  array<int, string>  $relations
     * @return array<int|string, mixed>
     */
    protected function restrictWarehouseRelations(array $relations, ?User $clientUser): array
    {
        if ($clientUser === null || ! in_array('warehouses', $relations, true)) {
            return $relations;
        }

        // Та же логика резолва региона, что и в StockService::resolveRegionId —
        // у клиента без region_id (редкость) берём регион по умолчанию, чтобы
        // складские колонки и поля «по региону клиента» были согласованы.
        $regionId = $clientUser->region_id ?? Region::defaultId();
        if ($regionId === null) {
            return $relations;
        }

        $accessibleIds = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $restricted = [];
        foreach ($relations as $relation) {
            if ($relation === 'warehouses') {
                // Пустой $accessibleIds => whereIn даёт пустую выборку,
                // т.е. регион без складов не видит ни одного остатка. Это и есть
                // желаемое поведение «нет доступа — нет данных».
                $restricted['warehouses'] = fn ($query) => $query
                    ->whereIn('warehouses.id', $accessibleIds);
            } else {
                $restricted[] = $relation;
            }
        }

        return $restricted;
    }
}
