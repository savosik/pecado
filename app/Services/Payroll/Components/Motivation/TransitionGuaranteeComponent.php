<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Доплата до гарантии переходного периода (п. 12.3 Положения).
 *
 * В течение первого квартала действия оплата не может быть ниже доли от среднего
 * заработка работника за три периода, предшествующих введению Положения.
 *
 * Единственный компонент, зависящий от итога, поэтому он идёт в схеме последним
 * и читает накопленную сумму из контекста. База среднего фиксируется один раз
 * на дату введения Положения и не пересчитывается — иначе доплата гонялась бы
 * за собственным хвостом.
 *
 * Доплата выводится отдельной строкой намеренно: скрытая компенсация не решает
 * задачу, ради которой введена, — снять страх перед переходом.
 */
class TransitionGuaranteeComponent extends AbstractComponent
{
    public function key(): string
    {
        return 'motivation_guarantee';
    }

    public function label(): string
    {
        return 'Доплата до гарантии перехода';
    }

    public function description(): string
    {
        return 'Страховка на первый квартал новой системы: если по новым правилам вышло меньше гарантированного минимума, разница доплачивается.';
    }

    public function howComputed(): string
    {
        return 'Гарантированный минимум = доля × средний заработок за три месяца до введения Положения. Доплата = минимум минус то, что вышло по расчёту.';
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
                'share' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Доля гарантии'],
                'base' => ['type' => 'number', 'minimum' => 0, 'title' => 'Средний заработок за три периода до перехода, ₽'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return [
            'share' => (float) (config('motivation.default_parameters.transition_guarantee_share') ?? 0),
            'base' => 0,
        ];
    }

    public function validateParams(array $params): array
    {
        $share = $this->number($params, 'share', -1);

        return $share < 0 || $share > 1
            ? ['Доля гарантии задаётся числом от 0 до 1 (0,9 — это 90 %).']
            : [];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $share = $this->number($params, 'share');
        $base = $this->number($params, 'base');
        $earned = $context->runningTotal;

        $minimum = Money::round($base * $share);
        $amount = Money::round(max(0.0, $minimum - $earned));

        if ($base <= 0 || $share <= 0) {
            return new ComponentResult(
                key: $this->key(),
                label: $this->label(),
                kind: $this->kind(),
                amount: 0.0,
                explanation: 'Гарантия переходного периода не установлена',
                meta: ['share' => $share, 'base' => $base, 'minimum' => null, 'earned' => $earned],
            );
        }

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $minimum,
            explanation: $amount > 0
                ? sprintf(
                    'Гарантированный минимум %s (%s от %s); по расчёту вышло %s — доплата %s',
                    Money::rub($minimum), Money::percent($share, 0), Money::rub($base),
                    Money::rub($earned), Money::rub($amount),
                )
                : sprintf(
                    'Расчёт %s не ниже гарантированного минимума %s — доплата не требуется',
                    Money::rub($earned), Money::rub($minimum),
                ),
            meta: ['share' => $share, 'base' => $base, 'minimum' => $minimum, 'earned' => $earned],
        );
    }
}
