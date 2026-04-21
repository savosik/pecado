<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Log;

/**
 * Обработчик события product.quantity.updated из очереди external.remains_for_website.
 *
 * Формат сообщения — не контракт 1С↔Сайт, а внешний ESB (service-products через shovel).
 * Сайт использует только остатки по складу «Тюмень Основной» — UUID склада задаётся в
 * config('erp.external_remains.tyumen_warehouse_uuid').
 *
 * Логика:
 *   1. Найти товар по event.payload.uid (Product.external_id). Fallback — Product.code.
 *   2. В event.payload.remains[] найти запись с warehouse_uid = UUID Тюмени.
 *   3. Доступный остаток = max(0, quantity - reserve), записать в pivot product_warehouse.
 *
 * Если склад Тюмень не зарегистрирован в warehouses.external_id — сообщение игнорируется
 * без ошибки (для локального dev без полного сид-дампа).
 */
class HandleExternalProductQuantityUpdated
{
    public function handle(array $envelope): void
    {
        $payload = $envelope['event']['payload'] ?? [];

        $productUuid = $payload['uid'] ?? null;
        $productCode = $payload['code'] ?? null;
        $remains = $payload['remains'] ?? [];

        if (! $productUuid && ! $productCode) {
            Log::warning('external.remains: payload без uid и code, пропускаем', [
                'envelope_uid' => $envelope['uid'] ?? null,
            ]);

            return;
        }

        $product = $productUuid
            ? Product::withoutGlobalScopes()->where('external_id', $productUuid)->first()
            : null;

        if (! $product && $productCode) {
            $product = Product::withoutGlobalScopes()->where('code', $productCode)->first();
        }

        if (! $product) {
            Log::info('external.remains: товар не найден по uid/code, событие проигнорировано', [
                'product_uuid' => $productUuid,
                'product_code' => $productCode,
            ]);

            return;
        }

        $tyumenUuid = config('erp.external_remains.tyumen_warehouse_uuid');

        $warehouse = Warehouse::where('external_id', $tyumenUuid)->first();

        if (! $warehouse) {
            Log::warning('external.remains: склад Тюмень не зарегистрирован, событие проигнорировано', [
                'expected_warehouse_uuid' => $tyumenUuid,
            ]);

            return;
        }

        $tyumenRemain = collect($remains)
            ->firstWhere('warehouse_uid', $tyumenUuid);

        if (! $tyumenRemain) {
            Log::info('external.remains: в remains[] нет записи по складу Тюмень, пропускаем', [
                'product_id' => $product->id,
                'product_uuid' => $productUuid,
            ]);

            return;
        }

        $quantity = (int) ($tyumenRemain['quantity'] ?? 0);
        $reserve = (int) ($tyumenRemain['reserve'] ?? 0);
        $available = max(0, $quantity - $reserve);

        $oldQuantity = $product->warehouses()
            ->where('warehouses.id', $warehouse->id)
            ->first()?->pivot?->quantity;

        $product->warehouses()->syncWithoutDetaching([
            $warehouse->id => ['quantity' => $available],
        ]);

        Log::info('external.remains: остаток по складу Тюмень обновлён', [
            'product_id' => $product->id,
            'product_uuid' => $productUuid,
            'product_code' => $productCode,
            'warehouse_id' => $warehouse->id,
            'raw_quantity' => $quantity,
            'raw_reserve' => $reserve,
            'old_available' => $oldQuantity,
            'new_available' => $available,
        ]);
    }
}
