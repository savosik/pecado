<?php

namespace App\Services\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Models\Motivation\MotivationShadowCalculation;
use App\Models\PayrollCalculation;
use App\Models\PayrollScheme;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\PayrollSchemeRepository;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Параллельный расчёт переходного периода (карточка mot-39; п. 12.2 Положения).
 *
 * Окно: N периодов до введения схемы 2.2 — оплата по действующей схеме, справочно
 * считается новая; M периодов после — оплата по новой, справочно показывается
 * прежняя. Справочный снимок хранится отдельно от payroll_calculations и в
 * ведомость не попадает.
 *
 * Разница объясняется по категориям — постоянная часть, переменная, корректировки,
 * гарантия — и разложением каждой стороны на строки: работник, у которого доход
 * падает, обязан видеть, какой именно показатель это делает.
 */
class ParallelCalculationService
{
    private const CATEGORIES = [
        'fixed' => ['label' => 'Постоянная часть', 'keys' => ['salary', 'motivation_channels_allowance', 'motivation_substitution']],
        'variable' => ['label' => 'Переменная часть', 'keys' => []],   // всё остальное с суммой
        'extra' => ['label' => 'Дополнительный доход', 'keys' => ['extra_income']],
        'correction' => ['label' => 'Корректировки руководителя', 'keys' => ['manual_correction']],
        'guarantee' => ['label' => 'Доплата до гарантии перехода', 'keys' => ['motivation_guarantee']],
    ];

    public function __construct(
        private readonly PayrollSchemeRepository $schemes,
        private readonly PayrollParamsResolver $params,
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculator $calculator,
        private readonly MotivationPresenter $presenter,
    ) {}

    /**
     * Схема 2.2 и границы окна параллельного расчёта; null — схема ещё не введена.
     *
     * @return array{scheme: PayrollScheme, from: CarbonImmutable, until: CarbonImmutable, effective_from: CarbonImmutable}|null
     */
    public function window(): ?array
    {
        $v2 = null;
        foreach ($this->schemes->versions() as $scheme) {
            foreach ($scheme->orderedComponents() as $entry) {
                if ($entry['key'] === 'motivation_variable' && $entry['enabled']) {
                    $v2 = $scheme;
                    break 2;
                }
            }
        }

        if ($v2 === null) {
            return null;
        }

        $effective = CarbonImmutable::instance($v2->effective_from)->startOfMonth();
        $before = max(0, (int) config('motivation.parallel.months_before', 2));
        $after = max(0, (int) config('motivation.parallel.months_after', 3));

        return [
            'scheme' => $v2,
            'effective_from' => $effective,
            'from' => $effective->subMonths($before),
            'until' => $effective->addMonths($after),   // не включая
        ];
    }

    public function inWindow(CarbonInterface $month): bool
    {
        $window = $this->window();
        $period = CarbonImmutable::instance($month)->startOfMonth();

        return $window !== null && $period->gte($window['from']) && $period->lt($window['until']);
    }

    /**
     * Сравнение оплачиваемого снимка со справочным по другой схеме.
     *
     * @return array<string, mixed>|null
     */
    public function compare(PayrollCalculation $paying): ?array
    {
        $period = CarbonImmutable::instance($paying->period_month)->startOfMonth();
        $window = $this->window();

        if ($window === null || ! $this->inWindow($period)) {
            return null;
        }

        $payingScheme = $this->schemes->forMonth($period);
        $shadowScheme = $this->otherScheme($payingScheme, $window['scheme']);

        if ($shadowScheme === null) {
            return null;
        }

        $shadow = $this->shadow((int) $paying->personal_manager_id, $period, $shadowScheme, $paying->isFrozen());
        $shadowIsV2 = (int) $shadowScheme->getKey() === (int) $window['scheme']->getKey();

        $payingSide = $this->side($payingScheme, (array) $paying->breakdown, (float) $paying->total, ! $shadowIsV2, $paying);
        $shadowSide = $this->side($shadowScheme, (array) $shadow->breakdown, (float) $shadow->total, $shadowIsV2, $this->transient($shadow, $paying));

        $categories = [];
        foreach (self::CATEGORIES as $key => $category) {
            $a = (float) ($payingSide['categories'][$key] ?? 0);
            $b = (float) ($shadowSide['categories'][$key] ?? 0);
            if (abs($a) < 0.005 && abs($b) < 0.005) {
                continue;
            }
            $categories[] = ['key' => $key, 'label' => $category['label'], 'paying' => Money::round($a), 'shadow' => Money::round($b), 'difference' => Money::round($b - $a)];
        }

        $difference = Money::round((float) $shadow->total - (float) $paying->total);
        $driver = null;
        foreach ($categories as $row) {
            if ($driver === null || abs($row['difference']) > abs($driver['difference'])) {
                $driver = $row;
            }
        }

        return [
            'phase' => $shadowIsV2 ? 'before' : 'after',
            'phase_label' => $shadowIsV2
                ? 'Переходный период: выплата по действующей системе, справочно — расчёт по Положению 2.2.'
                : 'Первые месяцы новой системы: справочно показано, сколько вышло бы по прежней схеме.',
            'window' => ['from' => $window['from']->toDateString(), 'until' => $window['until']->subMonth()->toDateString(), 'effective_from' => $window['effective_from']->toDateString()],
            'paying' => $payingSide,
            'shadow' => $shadowSide,
            'difference' => $difference,
            'categories' => $categories,
            'explanation' => $driver === null || abs($difference) < 0.005
                ? 'Итоги по обеим системам совпадают.'
                : sprintf(
                    'Разница %s складывается прежде всего из «%s»: %s против %s.',
                    Money::rub($difference),
                    mb_strtolower($driver['label']),
                    Money::rub($driver['shadow']),
                    Money::rub($driver['paying']),
                ),
            'computed_at' => $shadow->computed_at?->toIso8601String(),
        ];
    }

    /**
     * Короткая сводка для строки руководителя: итог по другой схеме и разница.
     *
     * @return array{scheme_label: string, phase: string, total: float, difference: float}|null
     */
    public function summary(PayrollCalculation $paying): ?array
    {
        $comparison = $this->compare($paying);

        if ($comparison === null) {
            return null;
        }

        return [
            'scheme_label' => $comparison['shadow']['scheme_label'],
            'phase' => $comparison['phase'],
            'total' => (float) $comparison['shadow']['total'],
            'difference' => (float) $comparison['difference'],
        ];
    }

    /**
     * Справочный снимок: считается заново, если его нет или он устарел; у
     * замороженного месяца не пересчитывается — сравнение обязано быть воспроизводимым.
     */
    private function shadow(int $managerId, CarbonImmutable $period, PayrollScheme $scheme, bool $frozen): MotivationShadowCalculation
    {
        $row = MotivationShadowCalculation::query()
            ->where('personal_manager_id', $managerId)
            ->whereDate('period_month', $period)
            ->where('scheme_id', $scheme->getKey())
            ->first();

        $stale = max(1, (int) config('motivation.parallel.stale_minutes', 10));
        if ($row !== null && ($frozen || ($row->computed_at !== null && $row->computed_at->greaterThan(now()->subMinutes($stale))))) {
            return $row;
        }

        $params = $this->params->effectiveForScheme($managerId, $scheme, $period);
        $inputs = $this->calculations->inputsFor($managerId, $period, $params);
        $hash = $inputs->hash();

        if ($row !== null && $row->inputs_hash === $hash && $row->params_effective == $params->toArray()) {
            $row->forceFill(['computed_at' => now()])->save();

            return $row;
        }

        $breakdown = $this->calculator->calculate($params, $inputs);

        $row ??= new MotivationShadowCalculation(['personal_manager_id' => $managerId, 'period_month' => $period->toDateString(), 'scheme_id' => $scheme->getKey()]);
        $row->forceFill([
            'params_effective' => $params->toArray(),
            'inputs' => $inputs->toArray(),
            'breakdown' => $breakdown->toArray(),
            'total' => $breakdown->total,
            'inputs_hash' => $hash,
            'computed_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * Строки и категории одной стороны сравнения.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function side(PayrollScheme $scheme, array $breakdown, float $total, bool $isV2, PayrollCalculation $calculation): array
    {
        $categories = [];
        $lines = [];

        foreach ((array) ($breakdown['components'] ?? []) as $component) {
            if (! is_array($component) || ($component['kind'] ?? null) !== ComponentKind::AMOUNT->value || ! isset($component['amount'])) {
                continue;
            }

            $key = (string) $component['key'];
            $amount = (float) $component['amount'];
            $category = 'variable';
            foreach (self::CATEGORIES as $categoryKey => $definition) {
                if (in_array($key, $definition['keys'], true)) {
                    $category = $categoryKey;
                }
            }
            $categories[$category] = ($categories[$category] ?? 0.0) + $amount;

            $lines[] = [
                'key' => $key,
                'label' => (string) ($component['label'] ?? $key),
                'amount' => Money::round($amount),
                'explanation' => (string) ($component['explanation'] ?? ''),
            ];
        }

        // У схемы 2.2 строки богаче: П1–К1 с пунктами Положения — тем же презентером, что «Мой месяц».
        if ($isV2) {
            $lines = array_map(fn (array $line): array => [
                'key' => $line['key'],
                'label' => $line['label'],
                'amount' => (float) $line['amount'],
                'explanation' => (string) ($line['explanation'] ?? ''),
                'clause' => $line['clause'] ?? null,
            ], $this->presenter->present($calculation)['lines']);
        }

        return [
            'scheme_label' => (string) $scheme->title,
            'scheme_version' => (int) $scheme->version,
            'is_v2' => $isV2,
            'total' => Money::round($total),
            'categories' => array_map(fn (float $v): float => Money::round($v), $categories),
            'lines' => $lines,
        ];
    }

    /**
     * Модель без сохранения — чтобы прогнать справочный снимок через презентер.
     */
    private function transient(MotivationShadowCalculation $shadow, PayrollCalculation $paying): PayrollCalculation
    {
        $calculation = new PayrollCalculation;
        $calculation->forceFill([
            'personal_manager_id' => $shadow->personal_manager_id,
            'period_month' => $shadow->period_month,
            'version' => (int) $paying->version,
            // Заморожен намеренно: рычаги и прогноз справочному снимку не нужны.
            'status' => PayrollCalculation::STATUS_APPROVED,
            'scheme_id' => $shadow->scheme_id,
            'params_effective' => $shadow->params_effective,
            'inputs' => $shadow->inputs,
            'breakdown' => $shadow->breakdown,
            'total' => $shadow->total,
            'computed_at' => $shadow->computed_at,
        ]);

        return $calculation;
    }

    /**
     * «Другая» схема: для месяца до введения — 2.2, после — предыдущая версия.
     */
    private function otherScheme(PayrollScheme $paying, PayrollScheme $v2): ?PayrollScheme
    {
        if ((int) $paying->getKey() !== (int) $v2->getKey()) {
            return $v2;
        }

        foreach ($this->schemes->versions() as $scheme) {
            if ((int) $scheme->getKey() !== (int) $v2->getKey() && $scheme->effective_from->lessThan($v2->effective_from)) {
                return $scheme;
            }
        }

        return null;
    }
}
