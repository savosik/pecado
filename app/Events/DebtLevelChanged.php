<?php

namespace App\Events;

use App\Enums\DebtLevel;
use App\Models\DebtState;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ступень долга контрагента изменилась в боевом режиме.
 *
 * Бросается только для строк контрагентов и только при dry_run = 0:
 * теневой расчёт действий не порождает. Письма, задачи и прочие следствия —
 * у слушателей, каждый проверяет свой рубильник DebtControl::live().
 */
class DebtLevelChanged
{
    use Dispatchable;

    public function __construct(
        public readonly DebtState $state,
        public readonly DebtLevel $from,
        public readonly DebtLevel $to,
    ) {}

    public function isEscalation(): bool
    {
        return $this->to->isWorseThan($this->from);
    }

    public function isRelief(): bool
    {
        return $this->from->isWorseThan($this->to);
    }
}
