<?php

namespace App\Services\Erp\Handlers;

use App\Models\Order;
use App\Models\Product;
use App\Services\Order\OrderChangeLogger;
use Illuminate\Support\Facades\Log;

/**
 * US-08 v11: Обработка события order.updated из 1С.
 *
 * Обновляет статус заказа и позиции по UUID.
 * v11: Записывает журнал изменений (diff) для прозрачности клиенту.
 * v12.1: Обновляет адрес доставки, если передан delivery_address.
 * v13: Логирует также атрибутные изменения (адрес, комментарий и т.д.).
 */
class HandleOrderUpdated
{
    public function __construct(
        private readonly OrderChangeLogger $changeLogger,
    ) {}

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleOrderUpdated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $order = Order::where('uuid', $uuid)->first();

        if (! $order) {
            Log::info('HandleOrderUpdated: заказ не найден', ['uuid' => $uuid]);

            return;
        }

        $order->load(['company', 'user']);
        $oldAttrs = $this->changeLogger->snapshotAttributes($order);

        // Обновление статуса
        if (isset($payload['status'])) {
            $rawStatus = $payload['status'];

            // Маппинг статусов из 1С (docs-erp/content/rules/orders.md)
            $statusMap = [
                'не согласован' => 'pending',
                'к выполнению' => 'confirmed',
                'к отгрузке' => 'ready_to_ship',
                'к_отгрузке' => 'ready_to_ship', // Вариант с подчеркиванием
                'закрыт' => 'closed',
                'удален' => 'deleted',
                'удалён' => 'deleted',
                'deleted' => 'deleted',
            ];

            $normalizedStatus = mb_strtolower(trim($rawStatus));
            $finalStatus = $statusMap[$normalizedStatus] ?? $rawStatus;

            $order->status = $finalStatus;
        }

        // Обновление номера из 1С (v12.3)
        if (isset($payload['number'])) {
            $order->erp_number = $payload['number'];
        }

        // Обновление адреса доставки (v12.1)
        if (isset($payload['delivery_address'])) {
            $order->delivery_address = $payload['delivery_address'];
        }

        $order->fromErp = true;
        $order->save();

        // Обновление позиций (если переданы) с журналированием
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItemsWithHistory($order, $payload['items']);
        }

        // Логируем атрибутные изменения (кроме статуса — он идёт в OrderStatusHistory)
        $order->refresh()->load(['company', 'user']);
        $newAttrs = $this->changeLogger->snapshotAttributes($order);
        $this->changeLogger->logAttributeChanges($order, $oldAttrs, $newAttrs, 'erp');

        Log::info('HandleOrderUpdated: заказ обновлён', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
            'delivery_address' => isset($payload['delivery_address']) ? 'обновлён' : 'не изменён',
        ]);
    }

    /**
     * Синхронизация позиций с записью истории изменений.
     */
    private function syncItemsWithHistory(Order $order, array $newItems): void
    {
        $oldSnapshot = $this->changeLogger->snapshotItems($order);
        $oldTotal = (float) $order->total_amount;

        $parsedItems = $this->parseNewItems($newItems);

        $order->items()->delete();

        foreach ($parsedItems as $item) {
            $order->items()->create([
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['final_price'],
                'base_price' => $item['base_price'],
                'discount_percent' => $item['discount_percent'],
                'final_price' => $item['final_price'],
                'subtotal' => $item['quantity'] * $item['final_price'],
            ]);
        }

        $newTotal = $order->items()->sum('subtotal');
        $order->total_amount = $newTotal;
        $order->saveQuietly();

        $newSnapshot = $this->changeLogger->snapshotItems($order->fresh());

        $this->changeLogger->logItemChanges(
            $order,
            $oldSnapshot,
            $newSnapshot,
            $oldTotal,
            (float) $newTotal,
            'erp',
        );
    }

    /**
     * Парсинг новых позиций из payload.
     * Возвращает массив с product_uuid как ключом.
     */
    private function parseNewItems(array $items): array
    {
        $parsed = [];

        foreach ($items as $item) {
            $productUuid = $item['product_uuid'] ?? '';
            $product = Product::withoutGlobalScopes()->where('external_id', $productUuid)->first();

            if (! $product) {
                Log::info('HandleOrderUpdated: товар не найден, позиция пропущена', [
                    'product_uuid' => $productUuid,
                ]);

                continue;
            }

            $quantity = $item['quantity'] ?? 0;
            $basePrice = $item['base_price'] ?? $item['price'] ?? 0;
            $discountPercent = $item['discount_percent'] ?? 0;
            $finalPrice = $item['final_price'] ?? $item['price'] ?? $basePrice;

            $parsed[$productUuid] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => (int) $quantity,
                'base_price' => (float) $basePrice,
                'discount_percent' => (float) $discountPercent,
                'final_price' => (float) $finalPrice,
            ];
        }

        return $parsed;
    }
}
