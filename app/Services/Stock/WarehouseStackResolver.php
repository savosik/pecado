<?php

namespace App\Services\Stock;

/**
 * Резолвер «выигравшего» склада в режиме стопки складов региона.
 *
 * Стопка — упорядоченная последовательность primary-складов (1 — верхний).
 * По каждому товару действует остаток самого верхнего склада, где товар
 * в наличии (quantity > 0); нижние склады — фолбэк по позициям, которых
 * нет выше. Остатки НЕ суммируются — строгое замещение.
 *
 * Класс чистый (без SQL) — все данные приходят параметрами.
 */
class WarehouseStackResolver
{
    /**
     * @param  list<int>  $orderedWarehouseIds  склады стопки сверху вниз
     * @param  array<int, array<int, int>>  $stock  product_id => (warehouse_id => quantity)
     * @return array<int, array{warehouse_id: int|null, quantity: int}>
     */
    public function resolve(array $orderedWarehouseIds, array $stock): array
    {
        $resolved = [];

        foreach ($stock as $productId => $byWarehouse) {
            $resolved[$productId] = ['warehouse_id' => null, 'quantity' => 0];

            foreach ($orderedWarehouseIds as $warehouseId) {
                $quantity = (int) ($byWarehouse[$warehouseId] ?? 0);

                if ($quantity > 0) {
                    $resolved[$productId] = [
                        'warehouse_id' => $warehouseId,
                        'quantity' => $quantity,
                    ];

                    break;
                }
            }
        }

        return $resolved;
    }
}
