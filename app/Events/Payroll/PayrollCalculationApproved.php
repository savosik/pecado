<?php

namespace App\Events\Payroll;

use App\Models\PayrollCalculation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Расчёт месяца утверждён и заморожен.
 *
 * Слушатели домена мотивации замораживают то, что должно читаться по состоянию
 * на момент утверждения: состав Фокус-перечня (п. 6.4.3). Событие синхронное —
 * снимок нужен до того, как руководитель изменит правила.
 */
class PayrollCalculationApproved
{
    use Dispatchable;

    public function __construct(public readonly PayrollCalculation $calculation) {}
}
