<?php

namespace App\Listeners\Motivation;

use App\Events\Payroll\PayrollCalculationApproved;
use App\Services\Motivation\FocusRuleService;

/**
 * При утверждении расчёта — снимок состава Фокус-перечня на этот период.
 *
 * Без снимка правка правил задним числом меняла бы состав уже утверждённого
 * месяца; со снимком резолвер читает период по нему (FocusRangeResolver::itemsFor).
 */
class FreezeFocusSnapshot
{
    public function __construct(private readonly FocusRuleService $rules) {}

    public function handle(PayrollCalculationApproved $event): void
    {
        $this->rules->freeze($event->calculation->period_month);
    }
}
