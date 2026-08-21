<?php

namespace App\Notifications\Pulse\Events\System;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Статус возврата изменился в 1С.
 */
class ReturnStatusChangedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'system.return_status_changed';
    }

    public function label(): string
    {
        return 'Смена статуса возврата';
    }

    public function description(): string
    {
        return 'Учётная система изменила статус заявки на возврат';
    }

    public function fields(): array
    {
        return [
            'status' => new FieldSpec('status', 'Новый статус', FieldSpec::TYPE_STRING),
            'previous_status' => new FieldSpec('previous_status', 'Прежний статус', FieldSpec::TYPE_STRING),
            'return_number' => new FieldSpec('return_number', 'Номер возврата', FieldSpec::TYPE_STRING),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Возврат {{order_number}}: изменился статус';
    }
}
