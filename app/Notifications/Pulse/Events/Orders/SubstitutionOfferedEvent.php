<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Менеджер отправил подборку замен по отменённым при сборке позициям.
 */
class SubstitutionOfferedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.substitution_offered';
    }

    public function label(): string
    {
        return 'Подобрана замена по недобору';
    }

    public function description(): string
    {
        return 'По отменённым позициям собрана и отправлена подборка замен';
    }

    public function fields(): array
    {
        return [
            'offer_items_count' => new FieldSpec('offer_items_count', 'Кандидатов в подборке', FieldSpec::TYPE_NUMBER),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}}: подобрали замену';
    }
}
