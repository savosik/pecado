<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationObjection;
use App\Models\PersonalManager;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Сводка отдела за месяц и ведомость к утверждению (карточка mot-34; формы B2, B3).
 *
 * Строится из тех же снимков, что «Мой месяц» каждого работника: сводка
 * руководителя и экран работника обязаны показывать одну и ту же цифру.
 * Рейтинга работников по доходу нет намеренно — сравнение ведётся
 * в показателях выполнения, а не в рублях зарплаты.
 */
class TeamSummaryService
{
    public function __construct(
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculator $calculator,
        private readonly MotivationAdvisor $advisor,
        private readonly ParallelCalculationService $parallel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $current = $period->equalTo(CarbonImmutable::now()->startOfMonth());

        $rows = [];
        foreach ($this->managers() as $manager) {
            $rows[] = $this->row($manager, $period, $current);
        }

        $sum = fn (string $key): float => Money::round(array_sum(array_map(fn (array $r): float => (float) ($r[$key] ?? 0), $rows)));
        $plan = $sum('plan');
        $shipped = $sum('shipped');
        $forecasts = array_filter(array_column($rows, 'forecast'), fn ($v): bool => $v !== null);
        $parallels = array_values(array_filter(array_column($rows, 'parallel'), fn ($v): bool => $v !== null));

        return [
            'month' => $period->toDateString(),
            'is_current_month' => $current,
            'department' => [
                'plan' => $plan,
                'shipped' => $shipped,
                'percent' => $plan > 0 ? round($shipped / $plan, 4) : null,
                'forecast' => $forecasts === [] ? null : Money::round(array_sum($forecasts)),
                'payroll' => $sum('total'),
                'variable' => $sum('variable'),
                'overdue' => $sum('overdue'),
                'note' => 'Квартальная премия отдела в фонд месяца не входит.',
            ],
            // Переходный период (п. 12.2): сколько стоит переход фонду — сумма разниц по работникам.
            'parallel' => $parallels === [] ? null : [
                'phase' => $parallels[0]['phase'],
                'scheme_label' => $parallels[0]['scheme_label'],
                'total' => Money::round(array_sum(array_column($parallels, 'total'))),
                'difference' => Money::round(array_sum(array_column($parallels, 'difference'))),
            ],
            'rows' => $rows,
            'readiness' => $this->readiness($rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PersonalManager $manager, CarbonImmutable $period, bool $current): array
    {
        $managerId = (int) $manager->getKey();
        $calculation = $this->calculations->ensureDraft($managerId, $period);
        $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
        $motivation = $inputs->motivation;
        $breakdown = (array) $calculation->breakdown;

        $variable = null;
        $amounts = [];
        foreach ((array) ($breakdown['components'] ?? []) as $component) {
            if (! is_array($component)) {
                continue;
            }
            $amounts[(string) $component['key']] = (float) ($component['amount'] ?? 0);
            if (($component['key'] ?? null) === 'motivation_variable') {
                $variable = $component;
            }
        }

        $meta = (array) ($variable['meta'] ?? []);
        $onScheme = $variable !== null;
        $plan = isset($meta['plan']) ? (float) $meta['plan'] : $inputs->plan;
        $shipped = $motivation->baseRevenue ?? $inputs->revenue;
        $overdue = array_sum(array_map(fn (array $r): float => (float) ($r['balance_end'] ?? 0), $motivation->overdueRows ?? []));
        $buyers = count(array_filter($motivation->baseRows ?? [], fn (array $r): bool => (float) ($r['amount'] ?? 0) > 0))
            + count(array_filter($motivation->newRows ?? [], fn (array $r): bool => (float) ($r['amount'] ?? 0) > 0));

        $forecast = null;
        if ($onScheme && $current && ! $calculation->isFrozen()) {
            $params = EffectiveParams::fromArray((array) $calculation->params_effective);
            $result = $this->calculator->calculate($params, $inputs);
            $forecast = $this->advisor->forecast($params, $inputs, $result, [])['expected'] ?? null;
        }

        $needsReview = count(array_filter($motivation->overdueRows ?? [], fn (array $r): bool => (bool) ($r['needs_review'] ?? false)));
        $openObjections = MotivationObjection::query()->where('calculation_id', $calculation->getKey())->where('status', MotivationObjection::STATUS_OPEN)->count();

        return [
            'manager' => ['id' => $managerId, 'name' => (string) $manager->name],
            'calculation' => [
                'id' => (int) $calculation->getKey(),
                'status' => $calculation->status,
                'status_label' => $calculation->statusLabel(),
                'frozen' => $calculation->isFrozen(),
                'version' => (int) $calculation->version,
                'computed_at' => $calculation->computed_at?->toIso8601String(),
                'approved_at' => $calculation->approved_at?->toIso8601String(),
                'paid_at' => $calculation->paid_at?->toIso8601String(),
                'comment' => $calculation->comment,
            ],
            'on_scheme_v2' => $onScheme,
            'plan' => $plan === null ? null : Money::round($plan),
            'shipped' => Money::round($shipped),
            'percent' => $plan !== null && $plan > 0 ? round($shipped / $plan, 4) : null,
            'p1' => (float) ($meta['p1'] ?? 0),
            'p2' => (float) ($meta['p2'] ?? 0),
            'p3' => (float) ($meta['p3'] ?? 0),
            'k1' => (float) ($meta['k1'] ?? 0),
            'variable' => (float) ($variable['amount'] ?? 0),
            'fixed' => Money::round(($amounts['salary'] ?? 0) + ($amounts['motivation_channels_allowance'] ?? 0) + ($amounts['motivation_substitution'] ?? 0)),
            'correction' => (float) ($amounts['manual_correction'] ?? 0),
            'guarantee' => (float) ($amounts['motivation_guarantee'] ?? 0),
            'total' => (float) $calculation->total,
            'overdue' => Money::round($overdue),
            'overdue_share' => $shipped > 0 ? round($overdue / $shipped, 4) : null,
            'active_partners' => $buyers,
            'partners_total' => count($motivation->baseRows ?? []) + count($motivation->newRows ?? []),
            'forecast' => $forecast,
            'needs_review' => $needsReview,
            'open_objections' => $openObjections,
            'has_plan' => $plan !== null && $plan > 0,
            'warnings' => array_values((array) ($breakdown['warnings'] ?? [])),
            'parallel' => $this->parallel->summary($calculation),
        ];
    }

    /**
     * Готовность к закрытию месяца: условие → выполнено ли, и ссылка туда, где закрывается.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, ok: bool, detail: string, href: string|null}>
     */
    private function readiness(array $rows): array
    {
        $review = array_sum(array_column($rows, 'needs_review'));
        $objections = array_sum(array_column($rows, 'open_objections'));
        $noPlan = array_filter($rows, fn (array $r): bool => $r['on_scheme_v2'] && ! $r['has_plan']);
        $offScheme = array_filter($rows, fn (array $r): bool => ! $r['on_scheme_v2']);

        return [
            [
                'key' => 'invoices',
                'label' => 'Все накладные размечены',
                'ok' => $review === 0,
                'detail' => $review === 0 ? 'Дата погашения восстановлена у всех накладных' : sprintf('Не разобрано накладных: %d — по ним вычет идёт по состоянию «не погашено»', $review),
                'href' => $review === 0 ? null : '/crm/salary/settings',
            ],
            [
                'key' => 'plans',
                'label' => 'Планы на месяц утверждены',
                'ok' => $noPlan === [],
                'detail' => $noPlan === [] ? 'У всех работников есть план' : 'Без плана: '.implode(', ', array_map(fn (array $r): string => $r['manager']['name'], $noPlan)),
                'href' => $noPlan === [] ? null : '/crm/motivation/plans',
            ],
            [
                'key' => 'objections',
                'label' => 'Возражения рассмотрены',
                'ok' => $objections === 0,
                'detail' => $objections === 0 ? 'Открытых возражений нет' : sprintf('Ждут ответа: %d', $objections),
                'href' => null,
            ],
            [
                'key' => 'scheme',
                'label' => 'Все считаются по Положению 2.2',
                'ok' => $offScheme === [],
                'detail' => $offScheme === [] ? 'Схема v2 действует для всех' : 'По прежней схеме: '.implode(', ', array_map(fn (array $r): string => $r['manager']['name'], $offScheme)),
                'href' => $offScheme === [] ? null : '/crm/motivation/settings',
            ],
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PersonalManager>
     */
    private function managers()
    {
        return PersonalManager::query()->where('payroll_enabled', true)->orderBy('name')->get();
    }
}
