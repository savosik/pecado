<?php

namespace App\Services\Payroll\Components\Kpi;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Штраф за финансовую дисциплину: накладные, закрытые в месяце с задержкой.
 *
 * Ступени — параметр: `tiers: [{from_days, to_days|null, coefficient}]`
 * по задержке в рабочих днях. Штраф = коэффициент × сумма всей накладной,
 * вычитается из факта выручки до сравнения с планом.
 */
class DisciplinePenaltyFactor extends AbstractComponent
{
    public function key(): string
    {
        return 'discipline_penalty';
    }

    public function label(): string
    {
        return 'Штраф за финансовую дисциплину';
    }

    public function description(): string
    {
        return 'Накладные, оплата по которым пришла с задержкой от срока. Штраф считается от всей суммы накладной и уменьшает факт выручки в том месяце, когда пришёл закрывающий платёж. Неоплаченные накладные не штрафуются — только оплаченные с опозданием.';
    }

    public function howComputed(): string
    {
        return 'Задержка = рабочие дни от срока оплаты до закрывающего платежа. По ступени задержки берётся коэффициент, сумма накладной × коэффициент вычитается из реализаций.';
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
                'tiers' => [
                    'type' => 'array',
                    'title' => 'Ступени задержки',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from_days' => ['type' => 'integer', 'minimum' => 1, 'title' => 'От, рабочих дней'],
                            'to_days' => ['type' => ['integer', 'null'], 'minimum' => 1, 'title' => 'До, рабочих дней (пусто — и дальше)'],
                            'coefficient' => ['type' => 'number', 'minimum' => 0, 'title' => 'Коэффициент'],
                        ],
                        'required' => ['from_days', 'coefficient'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tiers'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return ['tiers' => []];
    }

    public function validateParams(array $params): array
    {
        $tiers = $this->tiers($params);
        $errors = [];
        $previousTo = 0;

        foreach ($tiers as $index => $tier) {
            $n = $index + 1;

            if ($tier['from_days'] <= $previousTo) {
                $errors[] = sprintf('Ступень %d начинается раньше, чем закончилась предыдущая (%d ≤ %d).', $n, $tier['from_days'], $previousTo);
            }

            if ($tier['to_days'] !== null && $tier['to_days'] < $tier['from_days']) {
                $errors[] = sprintf('Ступень %d: «до» меньше, чем «от».', $n);
            }

            if ($tier['to_days'] === null && $index !== count($tiers) - 1) {
                $errors[] = sprintf('Ступень %d открыта («до» пусто), но не последняя.', $n);
            }

            $previousTo = $tier['to_days'] ?? PHP_INT_MAX;
        }

        return $errors;
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $tiers = $this->tiers($params);
        $total = 0.0;
        $evidence = [];

        foreach ($context->inputs->invoices as $invoice) {
            $tier = $this->tierFor($tiers, $invoice->delayWorkingDays);
            $penalty = $tier === null ? 0.0 : Money::round($invoice->amount * $tier['coefficient']);
            $total += $penalty;

            $evidence[] = array_merge($invoice->toArray(), [
                'tier' => $tier === null ? null : $this->tierLabel($tier),
                'coefficient' => $tier['coefficient'] ?? null,
                'penalty' => $penalty,
            ]);
        }

        $total = Money::round($total);
        $penalized = array_values(array_filter($evidence, fn (array $row): bool => $row['penalty'] > 0));

        $explanation = $penalized === []
            ? 'Оплат с задержкой в этом месяце нет — штрафа нет'
            : sprintf(
                'Штраф %s: %s',
                Money::rub($total),
                implode('; ', array_map(
                    fn (array $row): string => sprintf(
                        '%s на %s — задержка %d раб. дн. (%s) × %s = %s',
                        $row['erp_number'] ?? '№ —',
                        Money::rub($row['amount']),
                        $row['delay_working_days'],
                        $row['tier'],
                        Money::factor($row['coefficient']),
                        Money::rub($row['penalty']),
                    ),
                    $penalized,
                )),
            );

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            value: $total,
            explanation: $explanation,
            evidence: $evidence,
            meta: [
                'invoices_count' => count($context->inputs->invoices),
                'penalized_count' => count($penalized),
                'penalty' => $total,
                'tiers' => $tiers,
            ],
        );
    }

    /**
     * Ступень для задержки; вне ступеней (в том числе до первой) — штрафа нет.
     *
     * @param  list<array{from_days: int, to_days: int|null, coefficient: float}>  $tiers
     * @return array{from_days: int, to_days: int|null, coefficient: float}|null
     */
    public function tierFor(array $tiers, ?int $delayWorkingDays): ?array
    {
        if ($delayWorkingDays === null) {
            return null;
        }

        foreach ($tiers as $tier) {
            if ($delayWorkingDays >= $tier['from_days'] && ($tier['to_days'] === null || $delayWorkingDays <= $tier['to_days'])) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{from_days: int, to_days: int|null, coefficient: float}>
     */
    public function tiers(array $params): array
    {
        $tiers = [];

        foreach ((array) ($params['tiers'] ?? []) as $tier) {
            if (! is_array($tier) || ! isset($tier['from_days'])) {
                continue;
            }

            $tiers[] = [
                'from_days' => (int) $tier['from_days'],
                'to_days' => isset($tier['to_days']) && $tier['to_days'] !== '' ? (int) $tier['to_days'] : null,
                'coefficient' => (float) ($tier['coefficient'] ?? 0),
            ];
        }

        usort($tiers, fn (array $a, array $b): int => $a['from_days'] <=> $b['from_days']);

        return $tiers;
    }

    /**
     * @param  array{from_days: int, to_days: int|null, coefficient: float}  $tier
     */
    private function tierLabel(array $tier): string
    {
        return $tier['to_days'] === null
            ? sprintf('от %d дней', $tier['from_days'])
            : sprintf('%d–%d дней', $tier['from_days'], $tier['to_days']);
    }
}
