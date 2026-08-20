<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Покупка оформлена.
 *
 * Событие о покупке целиком, а не о документе: корзина может разложиться
 * на несколько заказов (обычный, предзаказ, уценка), и клиенту это одно
 * действие. Так же устроен существующий листенер SendOrdersPlacedEmail.
 */
class OrderCreatedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.created';
    }

    public function label(): string
    {
        return 'Оформлен заказ';
    }

    public function description(): string
    {
        return 'Клиент оформил покупку на сайте, через API или её завёл менеджер';
    }

    public function fields(): array
    {
        return [
            'order_type' => new FieldSpec('order_type', 'Тип заказа', FieldSpec::TYPE_ENUM, OrderStatusChangedEvent::typeOptions()),
            'orders_count' => new FieldSpec('orders_count', 'Документов в покупке', FieldSpec::TYPE_NUMBER,
                hint: 'Больше одного — корзина разложилась на обычный заказ и предзаказ'),
            'total' => new FieldSpec('total', 'Сумма покупки', FieldSpec::TYPE_MONEY),
            'items_count' => new FieldSpec('items_count', 'Позиций', FieldSpec::TYPE_NUMBER),
            'channel' => new FieldSpec('channel', 'Откуда заказ', FieldSpec::TYPE_ENUM, [
                ['value' => 'site', 'label' => 'Сайт'],
                ['value' => 'api', 'label' => 'API клиента'],
                ['value' => 'admin', 'label' => 'Админка'],
                ['value' => 'erp', 'label' => '1С'],
            ]),
            'has_preorder' => new FieldSpec('has_preorder', 'Есть предзаказ', FieldSpec::TYPE_BOOL),
            'is_first_order' => new FieldSpec('is_first_order', 'Первый заказ клиента', FieldSpec::TYPE_BOOL),
        ];
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.orders.created';
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}} принят';
    }
}
