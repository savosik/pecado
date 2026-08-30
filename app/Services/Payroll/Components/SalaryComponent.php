<?php

namespace App\Services\Payroll\Components;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Оклад — фиксированная часть дохода.
 */
class SalaryComponent extends AbstractComponent
{
    public function key(): string
    {
        return 'salary';
    }

    public function label(): string
    {
        return 'Оклад';
    }

    public function description(): string
    {
        return 'Фиксированная часть дохода за месяц. Не зависит от выручки, клиентов и просрочек.';
    }

    public function howComputed(): string
    {
        return 'Сумма из настроек менеджера на месяц; если на месяц не задана — постоянная, иначе — из схемы отдела.';
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
                'amount' => ['type' => 'number', 'minimum' => 0, 'title' => 'Оклад, ₽'],
            ],
            'required' => ['amount'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return ['amount' => 0];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $amount = Money::round($this->number($params, 'amount'));

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            explanation: sprintf('Оклад %s', Money::rub($amount)),
        );
    }
}
