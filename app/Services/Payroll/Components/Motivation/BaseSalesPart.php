<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * П1 — вознаграждение за продажи Закреплённой базе (п. 6.2 Положения).
 *
 * Ставка берётся не от всего вала, а от превышения Порога оплаты: до порога
 * работа считается оплаченной окладом. Порог применяется только здесь —
 * к П2 и П3 он отношения не имеет (п. 6.2.4).
 *
 * Показатель исчисляется от суммы базы за период. Поклиентной разбивки внутри
 * него нет намеренно (решение заказчика от 08.09.2026 по Приложению № 2, п. 3):
 * разбивка по партнёрам на экранах объясняет результат, но не участвует в расчёте.
 */
class BaseSalesPart extends AbstractComponent
{
    public function key(): string
    {
        return 'p1';
    }

    public function label(): string
    {
        return 'П1 — продажи закреплённой базе';
    }

    public function description(): string
    {
        return 'Основной показатель. Считается от того, насколько отгрузки вашей базы превысили порог оплаты.';
    }

    public function howComputed(): string
    {
        return 'Ставка × (отгрузки закреплённой базы − порог оплаты). Порог = доля от личного плана; ниже порога показатель не начисляется.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::AMOUNT;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rate_p1' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка П1, доля'],
                'payment_threshold' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Порог оплаты, доля личного плана'],
            ],
            'additionalProperties' => true,
        ];
    }

    public function defaults(): array
    {
        return [];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $motivation = $context->inputs->motivation;
        $rate = $this->number($params, 'rate_p1');
        $thresholdShare = $this->number($params, 'payment_threshold');
        $revenue = $motivation->baseRevenue ?? 0.0;

        $plan = $motivation?->reducedPlan($context->inputs->plan) ?? $context->inputs->plan;

        if ($plan === null || $plan <= 0) {
            return new ComponentResult(
                key: $this->key(),
                label: $this->label(),
                kind: $this->kind(),
                amount: 0.0,
                value: 0.0,
                explanation: 'Личный план на месяц не задан — показатель не начисляется',
                warnings: ['Личный план на месяц не задан: П1 не начисляется. План утверждается приказом на квартал.'],
                meta: ['plan' => null, 'threshold' => null, 'revenue' => $revenue],
            );
        }

        $threshold = Money::round($plan * $thresholdShare);
        $excess = $revenue - $threshold;
        $amount = $excess > 0 ? Money::round($excess * $rate) : 0.0;

        $explanation = $excess > 0
            ? sprintf(
                'Отгрузки базы %s − порог %s = %s; × %s = %s',
                Money::rub($revenue), Money::rub($threshold), Money::rub($excess),
                Money::percent($rate, 2), Money::rub($amount),
            )
            : sprintf(
                'Отгрузки базы %s не превысили порог %s — показатель не начисляется (не хватило %s)',
                Money::rub($revenue), Money::rub($threshold), Money::rub(abs($excess)),
            );

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $amount,
            explanation: $explanation,
            evidence: $motivation->baseRows ?? [],
            meta: [
                'rate' => $rate,
                'plan' => Money::round($plan),
                'threshold_share' => $thresholdShare,
                'threshold' => $threshold,
                'revenue' => $revenue,
                'excess' => Money::round($excess),
                'returns' => $motivation->returns['base'] ?? 0.0,
                'plan_reduced' => (bool) $motivation?->hasAbsence(),
            ],
        );
    }
}
