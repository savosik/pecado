<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Log;

class HandleStockUpdated
{
    /**
     * Обработка события stock.updated из 1С.
     *
     * Находит товар по product_uuid (external_id) и склад по warehouse_uuid (external_id),
     * обновляет остаток в pivot-таблице product_warehouse.
     * Если товар или склад не найден — событие игнорируется без ошибки.
     */
    public function handle(array $payload): void
    {
        $productUuid = $payload['product_uuid'] ?? null;
        $warehouseUuid = $payload['warehouse_uuid'] ?? null;
        $quantity = $payload['quantity'] ?? null;

        if (! $productUuid || ! $warehouseUuid || $quantity === null) {
            Log::warning('stock.updated: отсутствует product_uuid, warehouse_uuid или quantity', ['payload' => $payload]);

            return;
        }

        $product = Product::withoutGlobalScopes()->where('external_id', $productUuid)->first();

        if (! $product) {
            Log::info('stock.updated: товар не найден по UUID, событие проигнорировано', [
                'product_uuid' => $productUuid,
            ]);

            return;
        }

        $warehouse = Warehouse::where('external_id', $warehouseUuid)->first();

        if (! $warehouse) {
            Log::info('stock.updated: склад не найден по UUID, событие проигнорировано', [
                'warehouse_uuid' => $warehouseUuid,
            ]);

            return;
        }

        $oldQuantity = $product->warehouses()
            ->where('warehouses.id', $warehouse->id)
            ->first()?->pivot?->quantity;

        $product->warehouses()->syncWithoutDetaching([
            $warehouse->id => ['quantity' => (int) $quantity],
        ]);

        Log::info('stock.updated: остаток товара обновлён', [
            'product_id' => $product->id,
            'product_uuid' => $productUuid,
            'warehouse_id' => $warehouse->id,
            'warehouse_uuid' => $warehouseUuid,
            'old_quantity' => $oldQuantity,
            'new_quantity' => (int) $quantity,
        ]);
    }
}
