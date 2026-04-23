<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class HandlePriceUpdated
{
    /**
     * Обработка события price.updated из 1С.
     *
     * Находит товар по product_uuid (external_id) и обновляет базовую цену.
     * Если товар не найден — событие игнорируется без ошибки.
     */
    public function handle(array $payload): void
    {
        $productUuid = $payload['product_uuid'] ?? null;
        $price = $payload['price'] ?? null;

        if (! $productUuid || $price === null) {
            Log::warning('price.updated: отсутствует product_uuid или price', ['payload' => $payload]);

            return;
        }

        $product = Product::withoutGlobalScopes()->where('external_id', $productUuid)->first();

        if (! $product) {
            Log::info('price.updated: товар не найден по UUID, событие проигнорировано', [
                'product_uuid' => $productUuid,
            ]);

            return;
        }

        $oldPrice = $product->base_price;

        $product->update([
            'base_price' => $price,
        ]);

        Log::info('price.updated: цена товара обновлена', [
            'product_id' => $product->id,
            'product_uuid' => $productUuid,
            'old_price' => $oldPrice,
            'new_price' => $price,
        ]);
    }
}
