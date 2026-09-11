<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Support\Money;

/**
 * Чистый расчёт дохода: параметры + входы → разбор по компонентам и итог.
 *
 * Ни БД, ни времени: одни и те же входы всегда дают один и тот же разбор.
 * На этом держатся снимок (можно перечитать), прогноз и советы (те же входы
 * с гипотетической правкой).
 */
class PayrollCalculator
{
    public function __construct(private readonly PayrollCatalog $catalog) {}

    public function calculate(EffectiveParams $params, PayrollInputs $inputs): PayrollBreakdown
    {
        $results = [];
        $warnings = [];
        $running = 0.0;

        foreach ($params->order as $entry) {
            if (! $entry['enabled'] || ! $this->catalog->exists($entry['key'])) {
                continue;
            }

            // Сумма уже посчитанного нужна компонентам, которые по нормативу идут
            // последними и от итога зависят (доплата до гарантии). Порядок задаёт
            // схема; интерпретации «куда применить» здесь по-прежнему нет.
            $context = new PayrollContext($inputs, $params, Money::round($running));

            $component = $this->catalog->component($entry['key']);
            $result = $component->compute($context, $params->for($entry['key']));

            if ($result->kind !== ComponentKind::AMOUNT) {
                continue;   // факторы живут внутри владеющего компонента, в итог напрямую не идут
            }

            $results[] = $result;
            $running += (float) ($result->amount ?? 0.0);
            $warnings = array_merge($warnings, $result->warnings);
        }

        return new PayrollBreakdown($results, PayrollBreakdown::sum($results), array_values(array_unique($warnings)));
    }
}
