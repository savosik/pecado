<?php

namespace App\Observers;

use App\Enums\Crm\PlanTarget;
use App\Events\Payroll\PayrollInputsChanged;
use App\Models\CrmSalesPlan;
use App\Models\User;

/**
 * План менеджера или партнёра изменился → пересчитать черновик зарплаты за этот месяц.
 *
 * План отдела в формулу не входит, поэтому на него не реагируем.
 */
class PayrollSalesPlanObserver
{
    public function saved(CrmSalesPlan $plan): void
    {
        $this->notify($plan, 'plan.saved');
    }

    public function deleted(CrmSalesPlan $plan): void
    {
        $this->notify($plan, 'plan.deleted');
    }

    private function notify(CrmSalesPlan $plan, string $source): void
    {
        $managerId = match ($plan->target_type) {
            PlanTarget::MANAGER => (int) $plan->target_id,
            PlanTarget::CLIENT => User::query()->whereKey($plan->target_id)->value('personal_manager_id'),
            default => null,
        };

        if ($managerId === null || (int) $managerId === 0) {
            return;
        }

        PayrollInputsChanged::dispatch([(int) $managerId], $source, [$plan->period_month->toDateString()]);
    }
}
