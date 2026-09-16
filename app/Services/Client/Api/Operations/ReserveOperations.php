<?php

namespace App\Services\Client\Api\Operations;

use App\Models\Order;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Support\OperationApi\OperationInput;

/**
 * Режим «Заказы в резерве» (v16.9.0): что сейчас удержано на складе.
 *
 * Только чтение. Подтверждение, правка состава и отмена — отдельная карточка
 * (capi-06): это записи в шину 1С со своими кодами отказов.
 */
class ReserveOperations implements OperationProvider
{
    public static function section(): array
    {
        return ['reserves', 'Резервы'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'reserves.list',
                section: 'reserves',
                method: 'GET',
                uri: 'reserves',
                summary: 'Заказы в резерве: состав, суммы и срок удержания',
                description: 'Удержанные заказы клиента с фактическим сроком `reserved_until` (1С может урезать '
                    .'запрошенный срок до своего предела). Пока заказ в резерве, товар не уйдёт другому покупателю; '
                    .'без подтверждения до срока резерв снимется сам. `items_version` — версия состава: ключи строк '
                    .'стабильны только в её пределах, правка состава требует передать её как базовую. '
                    .'Отменённые в 1С строки в состав не входят.',
                params: [],
                handler: [self::class, 'list'],
                gate: FeatureGate::RESERVE,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $orders = Order::query()
            ->where('user_id', $actor->id)
            ->where('reserve', true)
            ->with(['items' => fn ($q) => $q->where('cancelled', false), 'items.product:id,sku,slug,name'])
            ->orderBy('reserved_until')
            ->orderBy('id')
            ->get();

        return Envelope::data($orders->map(fn (Order $order) => [
            'order_id' => $order->id,
            'number' => $order->erp_number ?? $order->number,
            'uuid' => $order->uuid,
            'total_amount' => (float) $order->total_amount,
            'currency_code' => $order->currency_code,
            'reserved_until' => $order->reserved_until?->toIso8601String(),
            'items_version' => (int) ($order->items_version ?? 0),
            'created_at' => ($order->erp_created_at ?? $order->created_at)?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'item_id' => $item->id,
                'sku' => $item->product?->sku,
                'name' => $item->product?->name ?? $item->name,
                'quantity' => (float) $item->quantity,
                'price' => (float) ($item->final_price ?? $item->price),
                'subtotal' => (float) $item->subtotal,
            ])->values()->all(),
        ])->values()->all(), [
            'total' => $orders->count(),
        ]);
    }
}
