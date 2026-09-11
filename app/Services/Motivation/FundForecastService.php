<?php

namespace App\Services\Motivation;

use App\Models\PersonalManager;
use App\Services\Motivation\Dto\MotivationInputs;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Прогноз фонда оплаты труда (карточка mot-38; форма B10).
 *
 * Считается тем же калькулятором с гипотетическими входами — второй формулы нет.
 * Сценарии: при текущем темпе · при выполнении плана · при перевыполнении на
 * четверть. Отдельно — стоимость гарантии переходного периода в каждом сценарии.
 *
 * Ограничение, которое экран обязан показывать: история продаж ведётся с января
 * 2026, поэтому сезонность за полный год не наблюдалась.
 */
class FundForecastService
{
    private const OVERPERFORMANCE = 0.25;

    public function __construct(
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculator $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $rows = [];

        foreach (PersonalManager::query()->where('payroll_enabled', true)->orderBy('name')->get() as $manager) {
            $row = $this->row((int) $manager->getKey(), (string) $manager->name, $period);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $totals = [];
        foreach (['current', 'pace', 'plan', 'over'] as $scenario) {
            $totals[$scenario] = [
                'total' => Money::round(array_sum(array_map(fn (array $r): float => (float) ($r['scenarios'][$scenario]['total'] ?? 0), $rows))),
                'guarantee' => Money::round(array_sum(array_map(fn (array $r): float => (float) ($r['scenarios'][$scenario]['guarantee'] ?? 0), $rows))),
                'variable' => Money::round(array_sum(array_map(fn (array $r): float => (float) ($r['scenarios'][$scenario]['variable'] ?? 0), $rows))),
                'available' => array_reduce($rows, fn (bool $carry, array $r): bool => $carry && ($r['scenarios'][$scenario] ?? null) !== null, $rows !== []),
            ];
        }

        return [
            'month' => $period->toDateString(),
            'rows' => $rows,
            'department' => $totals,
            'scenarios' => [
                'current' => ['label' => 'Уже начислено', 'hint' => 'Итог черновика по данным на сегодня.'],
                'pace' => ['label' => 'При текущем темпе', 'hint' => 'Отгрузки и просрочка растут тем же темпом до конца месяца.'],
                'plan' => ['label' => 'При выполнении плана', 'hint' => 'Отгрузки базы равны плану; новые партнёры и просрочка — по текущему темпу.'],
                'over' => ['label' => 'При перевыполнении на четверть', 'hint' => 'План × 1,25 при тех же пропорциях.'],
            ],
            'notes' => [
                'История продаж ведётся с января 2026: сезонность за полный год не наблюдалась, прогноз опирается на темп текущего месяца и план.',
                'Квартальная премия отдела в фонд месяца не входит и здесь не показана.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(int $managerId, string $name, CarbonImmutable $period): ?array
    {
        $calculation = $this->calculations->ensureDraft($managerId, $period);
        $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
        $motivation = $inputs->motivation;

        if ($motivation === null) {
            return null;   // работник не на схеме v2 — прогнозировать по новой формуле нечего
        }

        $stored = (array) ($calculation->params_effective ?? []);
        if ($stored === []) {
            return null;
        }
        $params = EffectiveParams::fromArray($stored);

        $current = $this->calculator->calculate($params, $inputs);
        $plan = $current->component('motivation_variable')?->meta['plan'] ?? $inputs->plan;
        $plan = $plan === null ? null : (float) $plan;

        $passed = max(0, (int) ($inputs->workingDays['passed'] ?? 0));
        $total = max(1, (int) ($inputs->workingDays['total'] ?? 0));
        $frozen = $calculation->isFrozen();
        $rate = ($frozen || $passed === 0 || $passed >= $total) ? 1.0 : $total / $passed;

        $paceInputs = [
            'base_revenue' => Money::round($motivation->baseRevenue * $rate),
            'new_partners_revenue' => Money::round($motivation->newPartnersRevenue * $rate),
            'focus_revenue' => Money::round($motivation->focusRevenue * $rate),
            'overdue_integral' => Money::round($motivation->overdueIntegral * $rate),
        ];

        $scenarios = [
            'current' => $this->scenario($params, $inputs, []),
            'pace' => $this->scenario($params, $inputs, $paceInputs),
            'plan' => $plan === null || $plan <= 0 ? null : $this->scenario($params, $inputs, $this->atPlan($paceInputs, $plan)),
            'over' => $plan === null || $plan <= 0 ? null : $this->scenario($params, $inputs, $this->atPlan($paceInputs, $plan * (1 + self::OVERPERFORMANCE))),
        ];

        return [
            'manager' => ['id' => $managerId, 'name' => $name],
            'frozen' => $frozen,
            'plan' => $plan === null ? null : Money::round($plan),
            'shipped' => Money::round($motivation->baseRevenue + $motivation->newPartnersRevenue),
            'days' => ['passed' => $passed, 'total' => $total],
            'scenarios' => $scenarios,
        ];
    }

    /**
     * @param  array<string, float>  $changes
     * @return array{total: float, variable: float, guarantee: float, fixed: float, revenue: float}
     */
    private function scenario(EffectiveParams $params, PayrollInputs $inputs, array $changes): array
    {
        $motivation = array_replace(($inputs->motivation ?? new MotivationInputs)->toArray(), $changes);
        $result = $this->calculator->calculate($params, $inputs->with(['motivation' => $motivation]));

        $variable = $result->amountOf('motivation_variable');
        $guarantee = $result->amountOf('motivation_guarantee');

        return [
            'total' => Money::round($result->total),
            'variable' => Money::round($variable),
            'guarantee' => Money::round($guarantee),
            'fixed' => Money::round($result->total - $variable - $guarantee - $result->amountOf('manual_correction')),
            'revenue' => Money::round((float) ($motivation['base_revenue'] ?? 0) + (float) ($motivation['new_partners_revenue'] ?? 0)),
        ];
    }

    /**
     * Отгрузки базы равны цели: план сравнивается с базой (П1, п. 6.2), новые
     * партнёры и перечень остаются по темпу — они в план не входят.
     *
     * @param  array<string, float>  $pace
     * @return array<string, float>
     */
    private function atPlan(array $pace, float $target): array
    {
        $factor = $pace['base_revenue'] > 0 ? $target / $pace['base_revenue'] : 1.0;

        return array_replace($pace, [
            'base_revenue' => Money::round($target),
            'focus_revenue' => Money::round($pace['focus_revenue'] * $factor),
        ]);
    }
}
