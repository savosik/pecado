<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * П3 — надбавка за отгрузки товаров Фокус-перечня (п. 6.4 Положения).
 *
 * Единственная группа, которая пересекается с двумя другими: одна и та же
 * отгрузка может дать и П1 (или П2), и П3 (п. 6.4.2). Двойного счёта здесь нет —
 * это разные показатели за разное: за объём и за состав проданного.
 *
 * Позиция перечня может иметь собственную повышенную ставку (п. 6.4.4);
 * в этом случае строка улики несёт ключ `rate`, и общая ставка к ней не применяется.
 */
class FocusRangePart extends AbstractComponent
{
    public function key(): string
    {
        return 'p3';
    }

    public function label(): string
    {
        return 'П3 — товары фокус-перечня';
    }

    public function description(): string
    {
        return 'Надбавка за продажи товаров из фокус-перечня. Начисляется дополнительно к П1 или П2 по тем же отгрузкам.';
    }

    public function howComputed(): string
    {
        return 'Ставка × отгрузки позиций перечня за вычетом возвратов. У отдельной позиции может быть своя повышенная ставка.';
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
                'rate_p3' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка П3, доля'],
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
        $rate = $this->number($params, 'rate_p3');
        $revenue = $motivation->focusRevenue ?? 0.0;
        $rows = $motivation->focusRows ?? [];

        $withOwnRate = array_values(array_filter($rows, fn (array $row): bool => isset($row['rate'])));

        if ($withOwnRate === []) {
            $amount = Money::round($revenue * $rate);
        } else {
            $amount = 0.0;
            $covered = 0.0;

            foreach ($rows as $row) {
                $rowAmount = (float) ($row['amount'] ?? 0);
                $rowRate = isset($row['rate']) ? (float) $row['rate'] : $rate;
                $amount += $rowAmount * $rowRate;
                $covered += $rowAmount;
            }

            // Остаток перечня, не разложенный на позиции, идёт по общей ставке.
            $amount = Money::round($amount + max(0.0, $revenue - $covered) * $rate);
        }

        $explanation = $revenue > 0
            ? sprintf(
                'Отгрузки фокус-перечня %s × %s = %s%s',
                Money::rub($revenue),
                Money::percent($rate, 2),
                Money::rub($amount),
                $withOwnRate === [] ? '' : sprintf(' (позиций с повышенной ставкой: %d)', count($withOwnRate)),
            )
            : 'Отгрузок товаров фокус-перечня в периоде не было';

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $amount,
            explanation: $explanation,
            evidence: $rows,
            meta: [
                'rate' => $rate,
                'revenue' => $revenue,
                'returns' => $motivation->returns['focus'] ?? 0.0,
                'positions' => count($rows),
                'positions_with_own_rate' => count($withOwnRate),
            ],
        );
    }
}
