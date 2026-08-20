<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * По заказу прошла отгрузка (создана реализация).
 */
class OrderShippedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.shipped';
    }

    public function label(): string
    {
        return 'Отгрузка по заказу';
    }

    public function description(): string
    {
        return 'Создана реализация — товар ушёл со склада';
    }

    public function fields(): array
    {
        return [
            'amount' => new FieldSpec('amount', 'Сумма отгрузки', FieldSpec::TYPE_MONEY),
            'shipment_number' => new FieldSpec('shipment_number', 'Номер реализации', FieldSpec::TYPE_STRING),
            'organization_id' => new FieldSpec('organization_id', 'Наше юрлицо', FieldSpec::TYPE_NUMBER),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}}: отгружен';
    }
}
