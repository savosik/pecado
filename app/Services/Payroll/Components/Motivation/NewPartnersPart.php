<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * П2 — вознаграждение за продажи Новым партнёрам (п. 6.3 Положения).
 *
 * Ставка выше основной и применяется к обороту целиком: порог оплаты сюда
 * не относится. Отгрузки Нового партнёра не входят в П1 и не увеличивают
 * Личный план (п. 6.3.3) — группы взаимоисключающи.
 */
class NewPartnersPart extends AbstractComponent
{
    public function key(): string
    {
        return 'p2';
    }

    public function label(): string
    {
        return 'П2 — продажи новым партнёрам';
    }

    public function description(): string
    {
        return 'Повышенная ставка за отгрузки партнёрам в период новизны. Порог оплаты к этому показателю не применяется.';
    }

    public function howComputed(): string
    {
        return 'Ставка × отгрузки партнёров, находящихся в периоде новизны, за вычетом возвратов.';
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
                'rate_p2' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка П2, доля'],
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
        $rate = $this->number($params, 'rate_p2');
        $revenue = $motivation->newPartnersRevenue ?? 0.0;
        $count = $motivation->newPartnersCount ?? 0;

        $amount = Money::round($revenue * $rate);

        $explanation = $revenue > 0
            ? sprintf(
                'Отгрузки новым партнёрам %s (%d) × %s = %s',
                Money::rub($revenue), $count, Money::percent($rate, 2), Money::rub($amount),
            )
            : 'Отгрузок новым партнёрам в периоде не было';

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $amount,
            explanation: $explanation,
            evidence: $motivation->newRows ?? [],
            meta: [
                'rate' => $rate,
                'revenue' => $revenue,
                'partners' => $count,
                'returns' => $motivation->returns['new'] ?? 0.0,
            ],
        );
    }
}
