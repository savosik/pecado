<?php

namespace App\Services\Order;

use App\Enums\DeliveryMethod;

/**
 * Запрос на размещение заказа по списку идентификаторов (client-api и API v1).
 *
 * @param  list<array{identifier: string, quantity: int}>  $products
 */
final readonly class PlacementRequest
{
    public function __construct(
        public array $products,
        public DeliveryMethod $deliveryMethod = DeliveryMethod::DELIVERY,
        public ?string $address = null,
        public ?string $comment = null,
        public bool $applyPromotions = false,
        public bool $reserve = false,
    ) {}
}
