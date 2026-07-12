<?php

namespace App\Enums;

/**
 * Способ доставки заказа. Английские ключи передаются по шине RabbitMQ
 * в поле `delivery_method` сообщений `order.created` / `order.updated`
 * (v15.3, см. docs-erp/content/rules/orders.md).
 */
enum DeliveryMethod: string
{
    case DELIVERY = 'delivery';
    case PICKUP = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::DELIVERY => 'Доставка',
            self::PICKUP => 'Самовывоз',
        };
    }
}
