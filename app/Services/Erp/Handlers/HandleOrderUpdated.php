<?php

namespace App\Services\Erp\Handlers;

use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * US-08 v11: Обработка события order.updated из 1С.
 *
 * Обновляет статус заказа и позиции по UUID.
 * v11: Записывает журнал изменений (diff) для прозрачности клиенту.
 * v12.1: Обновляет адрес доставки, если передан delivery_address.
 */
class HandleOrderUpdated
{
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
            $rawStatus = $payload['status'];
            
            // Маппинг статусов из 1С (docs-erp/content/rules/orders.md)
            $statusMap = [
                'не согласован' => 'pending',
                'к выполнению'  => 'confirmed',
                'к отгрузке'    => 'ready_to_ship',
                'к_отгрузке'    => 'ready_to_ship', // Вариант с подчеркиванием
                'закрыт'        => 'closed',
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

        $order->save();

        // Обновление позиций (если переданы) с журналированием
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItemsWithHistory($order, $payload['items']);
        }

        Log::info('HandleOrderUpdated: заказ обновлён', [
            'uuid'   => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
            'delivery_address' => isset($payload['delivery_address']) ? 'обновлён' : 'не изменён',
        ]);
    }

    /**
     * Синхронизация позиций с записью истории изменений.
     */
    private function syncItemsWithHistory(Order $order, array $newItems): void
    {
        // 1. Снимок текущих позиций (до изменения)
        $oldSnapshot = $this->takeSnapshot($order);
        $oldTotal = (float) $order->total_amount;

        // 2. Подготовить новые позиции
        $parsedItems = $this->parseNewItems($newItems);

        // 3. Вычислить diff
        $diff = $this->computeDiff($oldSnapshot, $parsedItems);

        // 4. Выполнить замену позиций
        $order->items()->delete();

        foreach ($parsedItems as $item) {
            $order->items()->create([
                'product_id'       => $item['product_id'],
                'name'             => $item['name'],
                'quantity'         => $item['quantity'],
                'price'            => $item['final_price'],
                'base_price'       => $item['base_price'],
                'discount_percent' => $item['discount_percent'],
                'final_price'      => $item['final_price'],
                'subtotal'         => $item['quantity'] * $item['final_price'],
            ]);
        }

        // 5. Пересчёт суммы
        $newTotal = $order->items()->sum('subtotal');
        $order->total_amount = $newTotal;
        $order->saveQuietly(); // Без событий — мы уже внутри ERP-обработки

        // 6. Записать историю, если есть изменения
        if ($this->hasMeaningfulChanges($diff, $oldTotal, (float) $newTotal)) {
            $summary = $this->buildSummary($diff, $oldTotal, (float) $newTotal);

            OrderChangeLog::create([
                'order_id'  => $order->id,
                'type'      => 'items_updated',
                'summary'   => $summary,
                'changes'   => $diff,
                'source'    => 'erp',
                'old_total' => $oldTotal,
                'new_total' => (float) $newTotal,
            ]);
        }
    }

    /**
     * Снимок текущих позиций заказа.
     * Ключ — external_id (UUID) товара.
     */
    private function takeSnapshot(Order $order): array
    {
        $snapshot = [];

        foreach ($order->items()->with('product')->get() as $item) {
            $key = $item->product?->external_id ?? 'unknown_' . $item->id;
            $snapshot[$key] = [
                'product_id'       => $item->product_id,
                'name'             => $item->product?->name ?? $item->name,
                'quantity'         => (int) $item->quantity,
                'base_price'       => (float) $item->base_price,
                'discount_percent' => (float) $item->discount_percent,
                'final_price'      => (float) $item->final_price,
            ];
        }

        return $snapshot;
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
            $product = Product::where('external_id', $productUuid)->first();

            if (!$product) {
                Log::info('HandleOrderUpdated: товар не найден, позиция пропущена', [
                    'product_uuid' => $productUuid,
                ]);
                continue;
            }

            $quantity        = $item['quantity']         ?? 0;
            $basePrice       = $item['base_price']       ?? $item['price'] ?? 0;
            $discountPercent = $item['discount_percent'] ?? 0;
            $finalPrice      = $item['final_price']      ?? $item['price'] ?? $basePrice;

            $parsed[$productUuid] = [
                'product_id'       => $product->id,
                'name'             => $product->name,
                'quantity'         => (int) $quantity,
                'base_price'       => (float) $basePrice,
                'discount_percent' => (float) $discountPercent,
                'final_price'      => (float) $finalPrice,
            ];
        }

        return $parsed;
    }

    /**
     * Вычислить diff между старыми и новыми позициями.
     */
    private function computeDiff(array $oldSnapshot, array $newItems): array
    {
        $diff = ['added' => [], 'removed' => [], 'modified' => []];

        // Добавленные: есть в новых, нет в старых
        foreach ($newItems as $uuid => $newItem) {
            if (!isset($oldSnapshot[$uuid])) {
                $diff['added'][] = [
                    'product_name' => $newItem['name'],
                    'quantity'     => $newItem['quantity'],
                    'price'        => $newItem['final_price'],
                ];
            }
        }

        // Удалённые: есть в старых, нет в новых
        foreach ($oldSnapshot as $uuid => $oldItem) {
            if (!isset($newItems[$uuid])) {
                $diff['removed'][] = [
                    'product_name' => $oldItem['name'],
                    'quantity'     => $oldItem['quantity'],
                    'price'        => $oldItem['final_price'],
                ];
            }
        }

        // Изменённые: есть в обоих
        foreach ($newItems as $uuid => $newItem) {
            if (!isset($oldSnapshot[$uuid])) {
                continue;
            }

            $oldItem = $oldSnapshot[$uuid];
            $changes = [];

            if ($oldItem['quantity'] !== $newItem['quantity']) {
                $changes['quantity'] = ['old' => $oldItem['quantity'], 'new' => $newItem['quantity']];
            }
            if (abs($oldItem['discount_percent'] - $newItem['discount_percent']) > 0.01) {
                $changes['discount_percent'] = ['old' => $oldItem['discount_percent'], 'new' => $newItem['discount_percent']];
            }
            if (abs($oldItem['final_price'] - $newItem['final_price']) > 0.01) {
                $changes['final_price'] = ['old' => $oldItem['final_price'], 'new' => $newItem['final_price']];
            }
            if (abs($oldItem['base_price'] - $newItem['base_price']) > 0.01) {
                $changes['base_price'] = ['old' => $oldItem['base_price'], 'new' => $newItem['base_price']];
            }

            if (!empty($changes)) {
                $diff['modified'][] = [
                    'product_name' => $newItem['name'],
                    'changes'      => $changes,
                ];
            }
        }

        return $diff;
    }

    /**
     * Есть ли значимые изменения?
     */
    private function hasMeaningfulChanges(array $diff, float $oldTotal, float $newTotal): bool
    {
        return !empty($diff['added'])
            || !empty($diff['removed'])
            || !empty($diff['modified'])
            || abs($oldTotal - $newTotal) > 0.01;
    }

    /**
     * Сгенерировать человекочитаемое описание на русском.
     */
    private function buildSummary(array $diff, float $oldTotal, float $newTotal): string
    {
        $lines = [];

        foreach ($diff['added'] as $item) {
            $lines[] = "Добавлен: «{$item['product_name']}» (кол-во: {$item['quantity']}, цена: {$item['price']} ₽)";
        }

        foreach ($diff['removed'] as $item) {
            $lines[] = "Удалён: «{$item['product_name']}»";
        }

        foreach ($diff['modified'] as $item) {
            $parts = [];
            $ch = $item['changes'];

            if (isset($ch['quantity'])) {
                $parts[] = "кол-во: {$ch['quantity']['old']} → {$ch['quantity']['new']}";
            }
            if (isset($ch['discount_percent'])) {
                $parts[] = "скидка: {$ch['discount_percent']['old']}% → {$ch['discount_percent']['new']}%";
            }
            if (isset($ch['final_price'])) {
                $parts[] = "цена: {$ch['final_price']['old']} → {$ch['final_price']['new']} ₽";
            }

            $changesStr = implode(', ', $parts);
            $lines[] = "Изменён: «{$item['product_name']}» — {$changesStr}";
        }

        if (abs($oldTotal - $newTotal) > 0.01) {
            $lines[] = "Сумма заказа: " . number_format($oldTotal, 2, '.', ' ') . " → " . number_format($newTotal, 2, '.', ' ') . " ₽";
        }

        return implode("\n", $lines);
    }
}
