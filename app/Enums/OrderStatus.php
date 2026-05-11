<?php

namespace App\Enums;

/**
 * Справочник статусов заказа. Источник истины — перечисление 1С:КА2
 * (см. docs-erp/content/rules/orders.md).
 *
 * Английские ключи передаются по шине RabbitMQ в полях `status` сообщений
 * `order.created` / `order.updated`. Маппинг русских названий из 1С — в
 * `App\Services\Erp\Support\OrderStatusMapper`.
 */
enum OrderStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case PENDING_PAYMENT_BEFORE_PROVISION = 'pending_payment_before_provision';
    case READY_FOR_PROVISION = 'ready_for_provision';
    case PENDING_PAYMENT_BEFORE_SHIPMENT = 'pending_payment_before_shipment';
    case AWAITING_PROVISION = 'awaiting_provision';
    case READY_FOR_SHIPMENT = 'ready_for_shipment';
    case SHIPPING = 'shipping';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case READY_FOR_CLOSURE = 'ready_for_closure';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'Ожидается согласование',
            self::PENDING_PAYMENT_BEFORE_PROVISION => 'Ожидается оплата до обеспечения',
            self::READY_FOR_PROVISION => 'Готов к обеспечению',
            self::PENDING_PAYMENT_BEFORE_SHIPMENT => 'Ожидается оплата до отгрузки',
            self::AWAITING_PROVISION => 'Ожидается обеспечение',
            self::READY_FOR_SHIPMENT => 'Готов к отгрузке',
            self::SHIPPING => 'В процессе отгрузки',
            self::AWAITING_PAYMENT => 'Ожидается оплата',
            self::READY_FOR_CLOSURE => 'Готов к закрытию',
            self::CLOSED => 'Закрыт',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'yellow',
            self::PENDING_PAYMENT_BEFORE_PROVISION => 'orange',
            self::READY_FOR_PROVISION => 'cyan',
            self::PENDING_PAYMENT_BEFORE_SHIPMENT => 'orange',
            self::AWAITING_PROVISION => 'blue',
            self::READY_FOR_SHIPMENT => 'purple',
            self::SHIPPING => 'teal',
            self::AWAITING_PAYMENT => 'orange',
            self::READY_FOR_CLOSURE => 'green',
            self::CLOSED => 'gray',
        };
    }
}
