<?php

namespace App\Services\Payroll\Dto;

/**
 * Всё, что видит компонент при расчёте: входы месяца, действующие параметры
 * и сумма уже посчитанных компонентов.
 *
 * `runningTotal` нужен единственному роду компонентов — тем, что по нормативу
 * вычисляются последними и от итога зависят: доплата до гарантии переходного
 * периода (п. 12.3 Положения о мотивации). Компонент по-прежнему не ходит в БД
 * и остаётся чистой функцией своих входов.
 */
final class PayrollContext
{
    public function __construct(
        public readonly PayrollInputs $inputs,
        public readonly EffectiveParams $params,
        public readonly float $runningTotal = 0.0,
    ) {}
}
