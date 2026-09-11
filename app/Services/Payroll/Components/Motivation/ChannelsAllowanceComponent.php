<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Надбавка за ведение корпоративных каналов и рассылок (п. 4.2 Положения).
 *
 * Постоянная величина, отдельной строкой, а не слитая с окладом: она назначается
 * не всем и снимается отдельно от оклада.
 */
class ChannelsAllowanceComponent extends AbstractComponent
{
    public function key(): string
    {
        return 'motivation_channels_allowance';
    }

    public function label(): string
    {
        return 'Надбавка за каналы и рассылки';
    }

    public function description(): string
    {
        return 'Постоянная надбавка за ведение корпоративных каналов и рассылок. От выручки не зависит.';
    }

    public function howComputed(): string
    {
        return 'Фиксированная сумма из действующего приказа; может быть отменена или изменена по работнику.';
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
                'amount' => ['type' => 'number', 'minimum' => 0, 'title' => 'Надбавка, ₽'],
            ],
            'required' => ['amount'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return ['amount' => (float) (config('motivation.default_parameters.channels_allowance') ?? 0)];
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $amount = Money::round($this->number($params, 'amount'));

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            explanation: sprintf('Надбавка за каналы и рассылки %s', Money::rub($amount)),
        );
    }
}
