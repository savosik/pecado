<?php

namespace App\Services\Payroll\Dto;

/**
 * Всё, что видит компонент при расчёте: входы месяца и действующие параметры.
 */
final class PayrollContext
{
    public function __construct(
        public readonly PayrollInputs $inputs,
        public readonly EffectiveParams $params,
    ) {}
}
