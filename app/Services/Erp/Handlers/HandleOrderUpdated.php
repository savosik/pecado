<?php

namespace App\Services\Erp\Handlers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class HandleOrderUpdated
{
    /**
     * Обработка события order.updated из 1С.
     * Обновляет статус заказа и позиции по UUID.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('HandleOrderUpdated: отсутствует uuid', ['payload' => $payload]);
            return;
        }

        $order = Order::where('uuid', $uuid)->first();

        if (!$order) {
            Log::info('HandleOrderUpdated: заказ не найден', ['uuid' => $uuid]);
            return;
        }

        // Обновление статуса
        if (isset($payload['status'])) {
            $order->status = $payload['status'];
        }

        $order->save();

        // Обновление позиций (если переданы)
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItems($order, $payload['items']);
        }

        Log::info('HandleOrderUpdated: заказ обновлён', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
        ]);
    }

    /**
     * Синхронизация позиций заказа.
     * Заменяет позиции на полученные из 1С.
     */
    private function syncItems(Order $order, array $items): void
    {
        // Удаляем старые позиции
        $order->items()->delete();

        foreach ($items as $item) {
            $product = Product::where('external_id', $item['product_uuid'] ?? '')->first();

            if (!$product) {
                Log::info('HandleOrderUpdated: товар не найден, позиция пропущена', [
                    'product_uuid' => $item['product_uuid'] ?? 'N/A',
                ]);
                continue;
            }

            $quantity        = $item['quantity']         ?? 0;
            $basePrice       = $item['base_price']       ?? $item['price'] ?? 0;
            $discountPercent = $item['discount_percent'] ?? 0;
            $finalPrice      = $item['final_price']      ?? $item['price'] ?? $basePrice;

            $order->items()->create([
                'product_id'       => $product->id,
                'name'             => $product->name,
                'quantity'         => $quantity,
                'price'            => $finalPrice,
                'base_price'       => $basePrice,
                'discount_percent' => $discountPercent,
                'final_price'      => $finalPrice,
                'subtotal'         => $quantity * $finalPrice,
            ]);
        }

        // Пересчёт общей суммы
        $order->total_amount = $order->items()->sum('subtotal');
        $order->save();
    }
}
