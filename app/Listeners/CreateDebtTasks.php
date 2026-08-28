<?php

namespace App\Listeners;

use App\Events\DebtLevelChanged;
use App\Events\DebtPauseExpired;

/**
 * Заглушка до debt-05: автозадачи менеджеру по переходам ступени.
 */
class CreateDebtTasks
{
    public function handle(DebtLevelChanged|DebtPauseExpired $event): void {}
}
