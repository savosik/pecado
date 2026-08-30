<?php

namespace App\Services\Payroll\Components;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\PayrollCatalog;
use App\Services\Payroll\Support\Money;

/**
 * KPI-премия: база × выполнение, где выполнение = множитель × (выручка − штраф) / план.
 *
 * Владеет тремя факторами и порядком их применения. Именно здесь, а не в общем
 * интерпретаторе «куда применить корректировку», живёт семантика формулы —
 * чтобы схема оставалась таблицей параметров, а не движком правил.
 *
 * Эффект каждого фактора в рублях считается what-if-изоляцией: премия без
 * фактора минус премия с ним. Тот же приём использует советник.
 */
class KpiBonusComponent extends AbstractComponent
{
    public function __construct(private readonly PayrollCatalog $catalog) {}

    public function key(): string
    {
        return 'kpi_bonus';
    }

    public function label(): string
    {
        return 'KPI-премия';
    }

    public function description(): string
    {
        return 'Переменная часть дохода. Растёт с выполнением плана по выручке, уменьшается штрафом за оплаты с задержкой и множителем за не удержанных плановых клиентов.';
    }

    public function howComputed(): string
    {
        return 'Из реализаций вычитается штраф за дисциплину, результат делится на план выручки и умножается на множитель по активным клиентам. Полученное выполнение × базовый размер премии; выше потолка (обычно 200 %) премия не растёт, ниже нуля не опускается.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::AMOUNT;
    }

    public function paramsSchema(): array
    {
        $properties = [
            'base' => ['type' => 'number', 'minimum' => 0, 'title' => 'Базовый размер премии, ₽'],
            'cap' => ['type' => 'number', 'minimum' => 1, 'maximum' => 10, 'title' => 'Потолок выполнения (2 = 200 %)'],
        ];

        foreach ($this->catalog->factorKeys() as $factorKey) {
            $schema = $this->catalog->factor($factorKey)->paramsSchema();
            if (($schema['properties'] ?? []) !== []) {
                $properties[$factorKey] = $schema;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['base', 'cap'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        $defaults = ['base' => 0, 'cap' => 2.0];

        foreach ($this->catalog->factorKeys() as $factorKey) {
            $factorDefaults = $this->catalog->factor($factorKey)->defaults();
            if ($factorDefaults !== []) {
                $defaults[$factorKey] = $factorDefaults;
            }
        }

        return $defaults;
    }

    public function validateParams(array $params): array
    {
        $errors = [];

        foreach ($this->catalog->factorKeys() as $factorKey) {
            $factor = $this->catalog->factor($factorKey);
            if ($factor->defaults() === []) {
                continue;
            }

            foreach ($factor->validateParams((array) ($params[$factorKey] ?? [])) as $error) {
                $errors[] = $factor->label().': '.$error;
            }
        }

        return $errors;
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $base = $this->number($params, 'base');
        $cap = max(0.0, $this->number($params, 'cap', 2.0));

        $factors = [];
        foreach ($this->catalog->factorKeys() as $factorKey) {
            $factors[$factorKey] = $this->catalog->factor($factorKey)->compute($context, (array) ($params[$factorKey] ?? []));
        }

        $revenue = (float) ($factors['revenue']->value ?? $context->inputs->revenue);
        $penalty = (float) ($factors['discipline_penalty']->value ?? 0.0);
        $multiplier = (float) ($factors['active_clients']->value ?? 1.0);
        $plan = $context->inputs->plan;

        $warnings = [];
        foreach ($factors as $factor) {
            $warnings = array_merge($warnings, $factor->warnings);
        }

        if ($plan === null || $plan <= 0) {
            $warnings[] = 'План выручки на месяц не задан — KPI-премия не считается. План ставится на странице «Планы продаж».';

            return new ComponentResult(
                key: $this->key(),
                label: $this->label(),
                kind: $this->kind(),
                amount: 0.0,
                value: 0.0,
                explanation: 'План выручки на месяц не задан — премия 0 ₽',
                children: array_values($factors),
                warnings: array_values(array_unique($warnings)),
                meta: ['base' => $base, 'cap' => $cap, 'plan' => null],
            );
        }

        $adjusted = $revenue - $penalty;
        $ratio = $adjusted / $plan;
        $raw = $multiplier * $ratio;
        $performance = min($cap, max(0.0, $raw));
        $amount = Money::round($base * $performance);

        // Эффекты факторов: премия без фактора минус премия с ним.
        $withoutPenalty = Money::round($base * min($cap, max(0.0, $multiplier * $revenue / $plan)));
        $withoutMultiplier = Money::round($base * min($cap, max(0.0, $ratio)));

        $children = [
            $factors['revenue'],
            $factors['discipline_penalty']->withEffect(Money::round($amount - $withoutPenalty)),
            $factors['active_clients']->withEffect(Money::round($amount - $withoutMultiplier)),
        ];

        $explanation = sprintf(
            'Реализации %s − штраф %s = %s; ÷ план %s = %s; × множитель %s = %s%s; премия %s × %s = %s',
            Money::rub($revenue),
            Money::rub($penalty),
            Money::rub($adjusted),
            Money::rub($plan),
            Money::percent($ratio),
            Money::factor($multiplier),
            Money::percent($raw),
            $raw > $cap ? sprintf(' (потолок %s)', Money::percent($cap, 0)) : ($raw < 0 ? ' (не ниже 0)' : ''),
            Money::rub($base),
            Money::percent($performance),
            Money::rub($amount),
        );

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $performance,
            explanation: $explanation,
            children: $children,
            warnings: array_values(array_unique($warnings)),
            meta: [
                'base' => $base,
                'cap' => $cap,
                'plan' => $plan,
                'revenue' => $revenue,
                'penalty' => $penalty,
                'adjusted' => Money::round($adjusted),
                'ratio' => $ratio,
                'multiplier' => $multiplier,
                'raw' => $raw,
                'performance' => $performance,
                'capped' => $raw > $cap,
                'without_penalty' => $withoutPenalty,
                'without_multiplier' => $withoutMultiplier,
                'max_amount' => Money::round($base * $cap),
            ],
        );
    }
}
