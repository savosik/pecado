<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Надбавка за замещение отсутствующего работника (пп. 4.3, 10.3 Положения).
 *
 * Считается по числу рабочих дней замещения из табеля. Обратная сторона п. 10.1:
 * у отсутствующего работника план уменьшается, а тот, кто вёл его партнёров,
 * получает надбавку. Показатели самих отгрузок при этом остаются за отсутствующим —
 * замещающий получает не их, а надбавку за дни.
 */
class SubstitutionAllowanceComponent extends AbstractComponent
{
    public function key(): string
    {
        return 'motivation_substitution';
    }

    public function label(): string
    {
        return 'Надбавка за замещение';
    }

    public function description(): string
    {
        return 'Оплата за дни, когда вы вели партнёров отсутствующего коллеги. Начисляется по табелю, за каждый рабочий день замещения.';
    }

    public function howComputed(): string
    {
        return 'Ставка за рабочий день × число рабочих дней замещения в периоде.';
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
                'per_day' => ['type' => 'number', 'minimum' => 0, 'title' => 'Надбавка за рабочий день замещения, ₽'],
            ],
            'required' => ['per_day'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return ['per_day' => (float) (config('motivation.default_parameters.substitution_per_day') ?? 0)];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $perDay = $this->number($params, 'per_day');
        $days = $context->inputs->motivation->substitutionDays ?? 0;
        $amount = Money::round($perDay * $days);

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: (float) $days,
            explanation: $days > 0
                ? sprintf('%d раб. дн. замещения × %s = %s', $days, Money::rub($perDay), Money::rub($amount))
                : 'Замещения в периоде не было',
            meta: ['per_day' => $perDay, 'days' => $days],
        );
    }
}
