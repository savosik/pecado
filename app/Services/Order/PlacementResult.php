<?php

namespace App\Services\Order;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Promotion\DTO\ClientApiPromoResult;

/**
 * Итог размещения: созданные заказы и то, что принять не удалось.
 *
 * @param  list<Order>  $orders
 * @param  list<array<string, mixed>>  $notAccepted  строки, не попавшие в заказ (not_found / out_of_stock)
 * @param  list<array<string, mixed>>  $partial  строки, принятые не в полном объёме
 */
final readonly class PlacementResult
{
    public function __construct(
        public array $orders,
        public array $notAccepted,
        public array $partial,
        public ?ClientApiPromoResult $promotions = null,
    ) {}

    public function fullyFulfilled(): bool
    {
        return $this->notAccepted === [] && $this->partial === [];
    }

    /**
     * Строки ответа по заказам — общий формат для legacy и v1.
     *
     * @return list<array<string, mixed>>
     */
    public function orderRows(): array
    {
        return array_map(fn (Order $order) => [
            'order_id' => $order->id,
            // Номер присваивает 1С после передачи; временный сайтовый ORD-… клиенту не отдаём
            'order_number' => $order->clientNumber(),
            'number_pending' => $order->clientNumberPending(),
            'number_hint' => $order->clientNumberPending() ? Order::pendingNumberHint() : null,
            'type' => $order->type?->value ?? 'order',
            'delivery_method' => $order->delivery_method?->value ?? DeliveryMethod::DELIVERY->value,
            'total_amount' => round((float) $order->total_amount, 2),
            'items_count' => $order->items()->count(),
            'status' => $order->status?->value ?? OrderStatus::PENDING_APPROVAL->value,
            // Запрошенный срок удержания; фактический (возможно, урезанный 1С)
            // виден в списке резервов после эха
            ...($order->reserve ? ['reserve' => true, 'reserved_until' => $order->reserved_until?->toIso8601String()] : []),
        ], $this->orders);
    }

    /**
     * Ответ legacy client-api — байт в байт прежний.
     *
     * @return array<string, mixed>
     */
    public function toLegacyResponse(): array
    {
        $response = [
            'orders' => $this->orderRows(),
            'total_orders' => count($this->orders),
            'fully_fulfilled' => $this->fullyFulfilled(),
        ];

        if (! $this->fullyFulfilled()) {
            $response['warnings'] = [
                'message' => 'Заказ принят. Часть позиций недоступна или отгружена не в полном объёме.',
                'not_accepted' => $this->notAccepted,
                'partial' => $this->partial,
            ];
        }

        // Блок появляется только при apply_promotions=true — даже пустым ключом
        // ответ раздувать нельзя, некоторые клиентские парсеры строгие
        if ($this->promotions !== null) {
            $response['promotions'] = $this->promotions->toResponse($this->orders);
        }

        return $response;
    }
}
