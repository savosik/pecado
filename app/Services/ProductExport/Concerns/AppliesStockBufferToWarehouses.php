<?php

namespace App\Services\ProductExport\Concerns;

use App\Models\User;
use App\Services\Stock\StockBufferService;
use App\Services\Stock\StockService;
use Illuminate\Support\Collection;

/**
 * Занижает загруженные в чанк остатки складов на страховой буфер (buf-05).
 *
 * Складские поля выгрузки (TotalStockField, WarehouseQuantityField,
 * WarehousesQuantityField) читают `$product->warehouses` напрямую, мимо
 * StockService, — без этого трейта клиент сегмента увидел бы в колонках
 * складов полный остаток, а в `user_stock_available` заниженный, и перестал
 * бы верить обоим числам.
 *
 * Буфер «выедается» из primary-складов региона клиента по порядку — с первого
 * склада с остатком, — чтобы сумма по складам сходилась с
 * `user_stock_available` до штуки. Preorder-склады не трогаются.
 * Мутируются только загруженные в память pivot-ы; в БД ничего не пишется.
 */
trait AppliesStockBufferToWarehouses
{
    /**
     * @param  Collection<int, \App\Models\Product>  $products
     */
    protected function applyStockBufferToWarehouses(Collection $products, ?User $clientUser): void
    {
        if (
            $clientUser === null
            || ! config('stock_buffer.enabled')
            || ! $clientUser->stock_buffer_enabled
        ) {
            return;
        }

        $loaded = $products->filter(fn ($product) => $product->relationLoaded('warehouses'));
        if ($loaded->isEmpty()) {
            return;
        }

        $buffers = app(StockBufferService::class)
            ->bufferMap($loaded->pluck('id')->all());

        $primaryIds = array_flip(
            app(StockService::class)->regionWarehouseIds($clientUser)['primary'],
        );

        foreach ($loaded as $product) {
            $left = $buffers[(int) $product->id] ?? 0;

            if ($left <= 0) {
                continue;
            }

            foreach ($product->warehouses as $warehouse) {
                if ($left <= 0) {
                    break;
                }

                if (! isset($primaryIds[$warehouse->id])) {
                    continue; // preorder не занижается
                }

                $quantity = (int) $warehouse->pivot->quantity;
                if ($quantity <= 0) {
                    continue;
                }

                $take = min($quantity, $left);
                $warehouse->pivot->quantity = $quantity - $take;
                $left -= $take;
            }
        }
    }
}
