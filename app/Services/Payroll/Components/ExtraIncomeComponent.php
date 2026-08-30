<?php

namespace App\Services\Payroll\Components;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Dto\AdjustmentInput;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Доп. доход — позиции, которые РОП заводит руками: ТГ-каналы, рассылки, лёгкий маркетинг.
 *
 * Параметров у компонента нет: позиции — строки `payroll_manual_adjustments`,
 * они приходят во входах. Так «добавить менеджеру рассылку за 3 000» — это
 * строка в таблице, а не правка схемы.
 */
class ExtraIncomeComponent extends AbstractComponent
{
    public function key(): string
    {
        return 'extra_income';
    }

    public function label(): string
    {
        return 'Доп. доход';
    }

    public function description(): string
    {
        return 'Оплата за задачи сверх продаж: телеграм-каналы, рассылки, лёгкий маркетинг.';
    }

    public function howComputed(): string
    {
        return 'Сумма позиций месяца (количество × цена). Позиции заводит руководитель.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::AMOUNT;
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
        return $this->sumItems($context->inputs->extraItems, 'Доп. доход');
    }

    /**
     * @param  list<AdjustmentInput>  $items
     */
    protected function sumItems(array $items, string $prefix): ComponentResult
    {
        $total = 0.0;
        $evidence = [];

        foreach ($items as $item) {
            $total += $item->amount;
            $evidence[] = $item->toArray();
        }

        $total = Money::round($total);

        $explanation = $items === []
            ? sprintf('%s: позиций нет', $prefix)
            : sprintf('%s: %s (%s)', $prefix, Money::rub($total), implode(', ', array_map(
                fn (AdjustmentInput $i): string => $i->qty == 1.0
                    ? sprintf('%s %s', $i->label, Money::rub($i->amount))
                    : sprintf('%s %s × %s = %s', $i->label, rtrim(rtrim(number_format($i->qty, 2, ',', ' '), '0'), ','), Money::rub($i->price), Money::rub($i->amount)),
                $items,
            )));

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $total,
            explanation: $explanation,
            evidence: $evidence,
            meta: ['items_count' => count($items)],
        );
    }
}
