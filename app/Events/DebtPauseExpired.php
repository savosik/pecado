<?php

namespace App\Events;

use App\Models\DebtPause;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Разблокировка истекла без оплаты — ограничения вернулись сами.
 * Поставившему её сотруднику полагается задача (слушатель задач).
 */
class DebtPauseExpired
{
    use Dispatchable;

    public function __construct(public readonly DebtPause $pause) {}
}
