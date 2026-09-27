<?php

namespace App\Services\Order;

use App\Enums\DeliveryMethod;

/**
 * Что клиент указал при оформлении корзины — одинаково для кабинета и API v1.
 */
final readonly class CheckoutRequestDto
{
    /**
     * @param  array<string, mixed>|null  $addressData
     */
    public function __construct(
        public DeliveryMethod $deliveryMethod = DeliveryMethod::DELIVERY,
        public ?string $deliveryAddress = null,
        public ?string $comment = null,
        public ?string $managerComment = null,
        public ?string $warehouseComment = null,
        public bool $instockOnly = false,
        public bool $reserve = false,
        public bool $saveAddress = false,
        public ?string $addressName = null,
        public bool $addressMakeDefault = false,
        public ?array $addressData = null,
    ) {}
}
