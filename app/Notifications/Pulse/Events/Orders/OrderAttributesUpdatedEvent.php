<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Изменение реквизитов заказа: компания, адрес доставки, комментарий.
 */
class OrderAttributesUpdatedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.attributes_updated';
    }

    public function label(): string
    {
        return 'Изменились реквизиты заказа';
    }

    public function description(): string
    {
        return 'Поменялись компания, адрес доставки, комментарий или другие поля заказа';
    }

    public function fields(): array
    {
        return [
            'changed_fields' => new FieldSpec('changed_fields', 'Изменённые поля', FieldSpec::TYPE_ARRAY,
                hint: 'Список названий полей — можно отобрать правило по конкретному реквизиту'),
            'source' => new FieldSpec('source', 'Источник правки', FieldSpec::TYPE_ENUM, [
                ['value' => 'erp', 'label' => '1С'],
                ['value' => 'admin', 'label' => 'Админка'],
                ['value' => 'api', 'label' => 'API клиента'],
            ]),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}}: изменились реквизиты';
    }
}
