<?php

namespace App\Notifications\Pulse\Events\System;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Клиент оформил возврат.
 */
class ReturnCreatedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'system.return_created';
    }

    public function label(): string
    {
        return 'Оформлен возврат';
    }

    public function description(): string
    {
        return 'Клиент создал заявку на возврат товара';
    }

    public function fields(): array
    {
        return [
            'return_number' => new FieldSpec('return_number', 'Номер возврата', FieldSpec::TYPE_STRING),
            'items_count' => new FieldSpec('items_count', 'Позиций в возврате', FieldSpec::TYPE_NUMBER),
            'total' => new FieldSpec('total', 'Сумма возврата', FieldSpec::TYPE_MONEY),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Возврат {{order_number}} принят';
    }
}
