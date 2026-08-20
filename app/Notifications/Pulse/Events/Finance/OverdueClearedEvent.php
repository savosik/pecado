<?php

namespace App\Notifications\Pulse\Events\Finance;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Просрочка погашена полностью.
 *
 * Заведено намеренно: менеджеру полезно узнать, что клиент рассчитался,
 * а не только что он должен.
 */
class OverdueClearedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'finance.overdue_cleared';
    }

    public function label(): string
    {
        return 'Просрочка погашена';
    }

    public function description(): string
    {
        return 'Просроченная задолженность контрагента закрыта полностью';
    }

    public function fields(): array
    {
        return [
            'was_days_overdue' => new FieldSpec('was_days_overdue', 'Было дней просрочки', FieldSpec::TYPE_NUMBER),
            'was_amount' => new FieldSpec('was_amount', 'Была сумма просрочки', FieldSpec::TYPE_MONEY),
        ];
    }

    protected function ownTags(array $data): array
    {
        return ['оплата:просрочка-погашена'];
    }

    public function defaultSubject(): string
    {
        return 'Задолженность погашена — спасибо!';
    }
}
