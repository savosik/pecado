<?php

namespace App\Services\Erp\Support;

use App\Models\ErpPendingPrice;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Базовая цена, пришедшая раньше карточки товара (v16.12.3, топик №12 Agent Hub).
 *
 * price.updated и product.created идут разными очередями с параллельными
 * воркерами, 1С публикует их в одну секунду — цена нередко обрабатывается
 * первой. Такую цену откладываем, а применяет её тот из двух обработчиков,
 * что завершился вторым: каждый после своей записи вызывает apply().
 */
class PendingPrices
{
    public function park(string $productUuid, float $price, ?string $messageId): void
    {
        ErpPendingPrice::query()->updateOrCreate(
            ['product_uuid' => $productUuid],
            ['price' => $price, 'message_id' => $messageId],
        );
    }

    /**
     * Применить отложенную цену, если и она, и карточка уже есть.
     * Возвращает применённую цену или null.
     */
    public function apply(string $productUuid): ?float
    {
        return DB::transaction(function () use ($productUuid): ?float {
            $pending = ErpPendingPrice::query()
                ->where('product_uuid', $productUuid)
                ->lockForUpdate()
                ->first();

            if (! $pending) {
                return null;
            }

            $product = Product::withoutGlobalScopes()
                ->where('external_id', $productUuid)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                return null;
            }

            $oldPrice = $product->base_price;
            $product->update(['base_price' => $pending->price]);
            $pending->delete();

            Log::info('price.updated: применена отложенная цена', [
                'product_id' => $product->id,
                'product_uuid' => $productUuid,
                'message_id' => $pending->message_id,
                'old_price' => $oldPrice,
                'new_price' => $pending->price,
            ]);

            return (float) $pending->price;
        });
    }

    /**
     * Прямое обновление цены снимает отложенную: иначе более старая цена
     * из очереди могла бы лечь поверх свежей.
     */
    public function discard(string $productUuid): void
    {
        ErpPendingPrice::query()->where('product_uuid', $productUuid)->delete();
    }
}
