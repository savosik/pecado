<?php

namespace App\Services\Payroll\Components\Kpi;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Выполнение плана по выручке: реализации за месяц против плана менеджера.
 */
class RevenueFactor extends AbstractComponent
{
    public function key(): string
    {
        return 'revenue';
    }

    public function label(): string
    {
        return 'Выполнение плана по выручке';
    }

    public function description(): string
    {
        return 'Сумма реализаций (отгрузок) ваших партнёров за месяц по дате документа 1С. План — по реализациям, а не по оплатам: неоплаченная накладная входит в факт полностью.';
    }

    public function howComputed(): string
    {
        return 'Реализации за месяц ÷ план выручки менеджера. Та же цифра, что на странице «Планы продаж».';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::ADJUSTMENT;
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function defaults(): array
    {
        return [];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $inputs = $context->inputs;
        $plan = $inputs->plan;
        $revenue = Money::round($inputs->revenue);
        $share = $plan !== null && $plan > 0 ? $revenue / $plan : null;

        $explanation = $plan === null
            ? sprintf('Реализации %s; план на месяц не задан', Money::rub($revenue))
            : sprintf('Реализации %s из плана %s = %s', Money::rub($revenue), Money::rub($plan), Money::percent($share));

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            value: $revenue,
            explanation: $explanation,
            evidence: [
                'plan' => $plan,
                'revenue' => $revenue,
                'share' => $share,
            ],
            meta: [
                'plan' => $plan,
                'revenue' => $revenue,
                'share' => $share,
                'remaining' => $plan !== null ? max(0.0, Money::round($plan - $revenue)) : null,
            ],
        );
    }
}
