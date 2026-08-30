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
        return 'Клиент заплатил позже срока — из выручки вычитается сумма накладной с коэффициентом. Пока накладная не оплачена, вычета нет.';
    }

    public function howComputed(): string
    {
        return 'Задержка в рабочих днях → ступень → коэффициент. Сумма накладной × коэффициент вычитается из реализаций.';
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

            // В улики попадают только оштрафованные: остальные раздували бы снимок,
            // а на экране всё равно показываются числом «закрыто в срок».
            if ($penalty > 0) {
                $evidence[] = array_merge($invoice->toArray(), [
                    'tier' => $tier === null ? null : $this->tierLabel($tier),
                    'coefficient' => $tier['coefficient'] ?? null,
                    'penalty' => $penalty,
                ]);
            }
        }

        $total = Money::round($total);
        $penalized = $evidence;
        $settledCount = count($context->inputs->invoices) + $context->inputs->settledOnTimeCount;

        $explanation = $penalized === []
            ? sprintf(
                'Оплат с задержкой в этом месяце нет — вычета нет%s',
                $settledCount === 0 ? '' : sprintf(' (закрыто накладных: %d)', $settledCount),
            )
            : $this->summarize($penalized, $total, $settledCount);

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            value: $total,
            explanation: $explanation,
            evidence: $evidence,
            meta: [
                'invoices_count' => $settledCount,
                'on_time_count' => max(0, $settledCount - count($penalized)),
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
     * Сводка по ступеням и три самых дорогих накладных — вместо списка на сотню строк.
     *
     * @param  list<array<string, mixed>>  $penalized
     */
    private function summarize(array $penalized, float $total, int $settledCount): string
    {
        $byTier = [];
        foreach ($penalized as $row) {
            $key = (string) $row['tier'];
            $byTier[$key] ??= ['count' => 0, 'amount' => 0.0, 'penalty' => 0.0, 'coefficient' => $row['coefficient']];
            $byTier[$key]['count']++;
            $byTier[$key]['amount'] += (float) $row['amount'];
            $byTier[$key]['penalty'] += (float) $row['penalty'];
        }

        $tiers = [];
        foreach ($byTier as $label => $sum) {
            $tiers[] = sprintf(
                '%s — %d накл. на %s × %s = %s',
                $label,
                $sum['count'],
                Money::rub($sum['amount']),
                Money::factor($sum['coefficient']),
                Money::rub($sum['penalty']),
            );
        }

        usort($penalized, fn (array $a, array $b): int => $b['penalty'] <=> $a['penalty']);
        $top = array_slice($penalized, 0, 3);
        $topText = implode('; ', array_map(
            fn (array $row): string => sprintf(
                '%s на %s (задержка %d раб. дн. → %s)',
                $row['erp_number'] ?? '№ —',
                Money::rub($row['amount']),
                $row['delay_working_days'],
                Money::rub($row['penalty']),
            ),
            $top,
        ));

        return sprintf(
            'Штраф %s по %d из %d закрытых накладных: %s. Самые дорогие: %s%s',
            Money::rub($total),
            count($penalized),
            $settledCount,
            implode('; ', $tiers),
            $topText,
            count($penalized) > 3 ? sprintf(' — и ещё %d', count($penalized) - 3) : '',
        );
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
