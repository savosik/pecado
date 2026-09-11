<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;

/**
 * К1 — вычет за просроченную задолженность партнёров (п. 6.5 Положения).
 *
 * Это НЕ штраф за дисциплину из sal-00. {@see \App\Services\Payroll\Components\Kpi\DisciplinePenaltyFactor}
 * считает задержку закрывающего платежа в рабочих днях и наказывает по факту оплаты;
 * К1 — интеграл остатка долга по календарным дням просрочки внутри периода.
 * Величины расходятся вдвое, поэтому переиспользовать один за другой нельзя.
 *
 * База начисления приходит уже посчитанной ({@see \App\Services\Motivation\Dto\MotivationInputs::$overdueIntegral}):
 * сумма произведений «остаток × дни просрочки». Считать её по остатку на конец
 * периода нельзя — это наказывало бы за долги, которые работник как раз собрал.
 *
 * Ограничения сверху у показателя нет (решение заказчика от 08.09.2026): свежая
 * просрочка — зона ответственности работника. От отрицательного дохода защищает
 * не потолок вычета, а нижнее ограничение переменной части (п. 6.6.1).
 */
class OverdueDebtPart extends AbstractComponent
{
    public function key(): string
    {
        return 'k1';
    }

    public function label(): string
    {
        return 'К1 — просроченная задолженность';
    }

    public function description(): string
    {
        return 'Вычет за долги ваших партнёров. Растёт каждый день, пока долг не погашен, и прекращается в день оплаты.';
    }

    public function howComputed(): string
    {
        return 'Ставка за день × сумма произведений «остаток долга × дни просрочки в периоде» по каждому документу. Долги, выведенные из базы начисления руководителем, не участвуют.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::ADJUSTMENT;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rate_k1_per_day' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка К1 за календарный день, доля'],
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
        $rate = $this->number($params, 'rate_k1_per_day');
        $integral = $motivation->overdueIntegral ?? 0.0;
        $rows = $motivation->overdueRows ?? [];

        $amount = Money::round($integral * $rate);
        $debt = Money::round(array_sum(array_map(fn (array $row): float => (float) ($row['balance_end'] ?? 0), $rows)));
        $asOf = $rows[0]['as_of'] ?? null;

        // В пояснении — рубли долга и рубли вычета. «База» в рубле-днях читалась как
        // сумма долга и пугала десятками миллионов (замечание заказчика 11.09.2026).
        $explanation = $integral > 0
            ? sprintf(
                'Просроченный долг %s — %s по %d %s. За каждый день просрочки — %s от остатка; за месяц набежало %s',
                $asOf === null ? 'сейчас' : 'на '.CarbonImmutable::parse((string) $asOf)->format('d.m.Y'),
                Money::rub($debt), count($rows), count($rows) === 1 ? 'документу' : 'документам',
                Money::percent($rate, 3), Money::rub($amount),
            )
            : 'Просроченной задолженности в периоде не было';

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $amount,
            explanation: $explanation,
            evidence: $rows,
            meta: [
                'rate_per_day' => $rate,
                'integral' => $integral,
                'debt' => $debt,
                'documents' => count($rows),
            ],
        );
    }
}
