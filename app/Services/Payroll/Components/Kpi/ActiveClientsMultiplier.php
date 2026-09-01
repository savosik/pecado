<?php

namespace App\Services\Payroll\Components\Kpi;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Dto\PlannedClientInput;
use App\Services\Payroll\Support\Money;

/**
 * Множитель по активным клиентам: удержал ли менеджер базу.
 *
 * План — партнёры с назначенным планом на месяц; факт — те из них, у кого была
 * отгрузка. Лестница — параметр `ladder: [{from_share, multiplier}]`: берётся
 * последняя ступень, чей порог не выше доли. Внеплановые покупатели не считаются:
 * показатель про базу, а не про везение.
 */
class ActiveClientsMultiplier extends AbstractComponent
{
    public function key(): string
    {
        return 'active_clients';
    }

    public function label(): string
    {
        return 'Активные плановые клиенты';
    }

    public function description(): string
    {
        return 'Сколько плановых партнёров купили в этом месяце. Плановые — те, кому поставлен план на месяц; купившие без плана сюда не входят, поэтому число меньше, чем «покупали в месяце» на «Планах продаж». Партнёр с несколькими юрлицами — один.';
    }

    public function howComputed(): string
    {
        return 'Купившие ÷ плановые → доля → множитель по лестнице. На него умножается выполнение плана.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::FACTOR;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ladder' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'title' => 'Лестница множителя',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from_share' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10, 'title' => 'От доли (0,8 = 80 %)'],
                            'multiplier' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10, 'title' => 'Множитель'],
                        ],
                        'required' => ['from_share', 'multiplier'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['ladder'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        return ['ladder' => [['from_share' => 0, 'multiplier' => 1.0]]];
    }

    public function validateParams(array $params): array
    {
        $ladder = $this->ladder($params);
        $errors = [];

        if ($ladder === []) {
            return ['Лестница пуста.'];
        }

        if (abs($ladder[0]['from_share']) > 1e-9) {
            $errors[] = 'Первая ступень лестницы должна начинаться с доли 0.';
        }

        for ($i = 1; $i < count($ladder); $i++) {
            if ($ladder[$i]['from_share'] <= $ladder[$i - 1]['from_share']) {
                $errors[] = sprintf('Ступень %d: порог доли не выше предыдущего.', $i + 1);
            }
        }

        return $errors;
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $ladder = $this->ladder($params);
        $planned = count($context->inputs->plannedClients);
        $active = count($context->inputs->activeClients());
        $warnings = [];

        if ($planned === 0) {
            $multiplier = 1.0;
            $share = null;
            $step = null;
            $warnings[] = 'Плановые клиенты на месяц не назначены — множитель по активным клиентам принят за 1,0.';
            $explanation = 'Плановых клиентов нет — множитель 1,0';
        } else {
            $share = $active / $planned;
            $step = $this->stepFor($ladder, $share);
            $multiplier = $step['multiplier'] ?? 1.0;
            $explanation = sprintf(
                'Активных %d из %d плановых = %s → множитель %s',
                $active,
                $planned,
                Money::percent($share, 0),
                Money::factor($multiplier),
            );
        }

        $next = $share === null ? null : $this->nextStep($ladder, $share, $planned, $active);

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            value: $multiplier,
            explanation: $explanation,
            evidence: array_map(fn (PlannedClientInput $c): array => $c->toArray() + ['active' => $c->isActive()], $context->inputs->plannedClients),
            warnings: $warnings,
            meta: [
                'planned' => $planned,
                'active' => $active,
                'share' => $share,
                'multiplier' => $multiplier,
                'step' => $step,
                'next_step' => $next,
                'ladder' => $ladder,
            ],
        );
    }

    /**
     * Последняя ступень, чей порог не выше доли.
     *
     * @param  list<array{from_share: float, multiplier: float}>  $ladder
     * @return array{from_share: float, multiplier: float}|null
     */
    public function stepFor(array $ladder, float $share): ?array
    {
        $current = null;

        foreach ($ladder as $step) {
            if ($share + 1e-9 >= $step['from_share']) {
                $current = $step;
            }
        }

        return $current;
    }

    /**
     * Ближайшая ступень выше текущей и сколько клиентов до неё не хватает.
     *
     * @param  list<array{from_share: float, multiplier: float}>  $ladder
     * @return array{from_share: float, multiplier: float, clients_needed: int}|null
     */
    public function nextStep(array $ladder, float $share, int $planned, int $active): ?array
    {
        foreach ($ladder as $step) {
            if ($step['from_share'] > $share + 1e-9) {
                return $step + ['clients_needed' => max(1, (int) ceil($step['from_share'] * $planned - 1e-9) - $active)];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{from_share: float, multiplier: float}>
     */
    public function ladder(array $params): array
    {
        $ladder = [];

        foreach ((array) ($params['ladder'] ?? []) as $step) {
            if (! is_array($step) || ! isset($step['from_share'], $step['multiplier'])) {
                continue;
            }

            $ladder[] = [
                'from_share' => (float) $step['from_share'],
                'multiplier' => (float) $step['multiplier'],
            ];
        }

        usort($ladder, fn (array $a, array $b): int => $a['from_share'] <=> $b['from_share']);

        return $ladder;
    }
}
