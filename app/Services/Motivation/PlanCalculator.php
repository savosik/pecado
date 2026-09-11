<?php

namespace App\Services\Motivation;

use App\Models\ManagerAbsence;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\PersonalManager;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Личный план на квартал по формуле Положения (карточка mot-25, п. 5.2).
 *
 *     База месяца = Медиана отгрузок за отработанный рабочий день
 *                 × Рабочие дни месяца × Сезонный коэффициент × (1 + Целевой прирост)
 *
 * Медиана берётся по дням, а не по месяцам. Разница не косметическая: на боевых
 * данных медиана дневных сумм даёт 244 645 ₽ у Сухова, а медиана помесячных
 * средних — 316 333 ₽, то есть план был бы завышен на треть. Норма говорит
 * «за отработанный рабочий день», и это распределение дней, а не месяцев:
 * усреднение внутри месяца стирает слабые дни, ради которых медиана и берётся
 * вместо среднего.
 *
 * Дни отсутствия из выборки исключаются (п. 10.5) — иначе отпуск занижал бы
 * медиану и вместе с ней план следующего квартала.
 *
 * Отгрузки партнёров в Периоде новизны в план не включаются (п. 6.3.3):
 * иначе привлечение партнёра само себе поднимало бы планку.
 */
class PlanCalculator
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly PartnerAttributionResolver $attribution,
        private readonly WorkingCalendar $calendar,
    ) {}

    /**
     * Расчёт плана на квартал.
     *
     * @param  array<string, mixed>  $params  параметры приказа; пусто — умолчания Приложения № 1
     * @return array{
     *     median_per_day: float,
     *     working_days: array<string, int>,
     *     seasonal: array<string, float>,
     *     growth_rate: float,
     *     overperformance_carry: float,
     *     previous_quarter_total: float|null,
     *     previous_quarter_comparable: bool,
     *     decline_limited: bool,
     *     values: array<string, float>,
     *     previous_values: array<string, float|null>,
     *     sample: array{days: int, excluded_days: int, zero_days: int, from: string, to: string, by_month: list<array{month: string, amount: float, working_days: int, excluded_days: int}>}
     * }
     */
    public function calculate(int $managerId, CarbonInterface $quarter, array $params = [], bool $waiveDeclineLimit = false): array
    {
        $start = CarbonImmutable::instance($quarter)->startOfQuarter()->startOfDay();
        $defaults = (array) config('motivation.default_parameters', []);

        $depth = max(1, (int) ($params['median_depth_periods'] ?? $defaults['median_depth_periods'] ?? 6));
        $growth = (float) ($params['growth_rate'] ?? $defaults['growth_rate'] ?? 0);
        $seasonalMap = (array) ($params['seasonal'] ?? $defaults['seasonal'] ?? []);
        $carryShare = (float) ($params['overperformance_share'] ?? $defaults['overperformance_share'] ?? 0.5);
        $declineLimit = (float) ($params['plan_decline_limit'] ?? $defaults['plan_decline_limit'] ?? 0.2);

        $sample = $this->dailyAmounts($managerId, $start->subMonths($depth), $start->subDay());
        $median = $this->median($sample['amounts']);

        $carry = $this->overperformanceCarry($managerId, $start, $carryShare);
        $previousTotal = $this->previousQuarterPlan($managerId, $start);

        $workingDays = [];
        $seasonal = [];
        $values = [];

        for ($i = 0; $i < 3; $i++) {
            $month = $start->addMonths($i);
            $key = $month->toDateString();

            $days = $this->calendar->monthDays($month)['total'];
            $coefficient = (float) ($seasonalMap[$month->month] ?? $seasonalMap[(string) $month->month] ?? 1.0);

            $workingDays[$key] = $days;
            $seasonal[$key] = $coefficient;
            $values[$key] = Money::round($median * $days * $coefficient * (1 + $growth) + $carry / 3);
        }

        $declineLimited = false;
        $comparable = $this->previousQuarterIsComparable($managerId, $start);

        if ($previousTotal !== null && $previousTotal > 0 && $comparable && ! $waiveDeclineLimit) {
            $floor = $previousTotal * (1 - $declineLimit);
            $total = array_sum($values);

            if ($total > 0 && $total < $floor) {
                // Ограничитель применяется к кварталу целиком и раскладывается
                // на месяцы пропорционально: иначе он исказил бы сезонность,
                // ради которой коэффициенты и вводились.
                $factor = $floor / $total;
                $values = array_map(fn (float $value): float => Money::round($value * $factor), $values);
                $declineLimited = true;
            }
        }

        return [
            'median_per_day' => Money::round($median),
            'working_days' => $workingDays,
            'seasonal' => $seasonal,
            'growth_rate' => $growth,
            'overperformance_carry' => Money::round($carry),
            'previous_quarter_total' => $previousTotal === null ? null : Money::round($previousTotal),
            'previous_quarter_comparable' => $comparable,
            'decline_limited' => $declineLimited,
            'values' => $values,
            'previous_values' => $this->currentPlans($managerId, $start),
            'sample' => [
                'days' => count($sample['amounts']),
                'excluded_days' => $sample['excluded'],
                // Дни без единой отгрузки медиану занижают. Их много — либо
                // работник действительно простаивал, либо данные периода неполны;
                // различить это может только человек, и он обязан это видеть.
                'zero_days' => count(array_filter($sample['amounts'], fn (float $v): bool => $v <= 0.0)),
                'by_month' => $sample['by_month'],
                'from' => $start->subMonths($depth)->toDateString(),
                'to' => $start->subDay()->toDateString(),
            ],
        ];
    }

    /**
     * Суммы отгрузок Закреплённой базы по отработанным рабочим дням.
     *
     * Окно разбирается по месяцам, а не целиком: партнёр исключается из базы
     * только за те месяцы, в которых он был в Периоде новизны (п. 6.3.3).
     * Исключение партнёра из всей выборки выбрасывало бы и его последующие
     * отгрузки — а при истории с января 2026 в новизне побывали почти все,
     * и медиана обращалась в ноль.
     *
     * @return array{amounts: list<float>, excluded: int, by_month: list<array{month: string, amount: float, working_days: int, excluded_days: int}>}
     */
    private function dailyAmounts(int $managerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $absence = $this->absentDays($managerId, $from, $to);

        $amounts = [];
        $excluded = 0;
        $byMonth = [];

        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $monthStart = $month->greaterThan($from) ? $month : $from;
            $monthEnd = $month->endOfMonth()->startOfDay();
            $monthEnd = $monthEnd->greaterThan($to) ? $to : $monthEnd;

            $byDay = $this->dailySeries($managerId, $month, $monthStart, $monthEnd);
            $summary = ['month' => $month->toDateString(), 'amount' => 0.0, 'working_days' => 0, 'excluded_days' => 0];

            for ($day = $monthStart; $day->lte($monthEnd); $day = $day->addDay()) {
                if (! $this->calendar->isWorkingDay($day)) {
                    continue;
                }

                if (in_array($day->toDateString(), $absence, true)) {
                    $excluded++;
                    $summary['excluded_days']++;

                    continue;
                }

                // День без отгрузок — это ноль, а не пропуск: медиана берётся вместо
                // среднего именно затем, чтобы слабые дни были видны.
                $amount = $byDay[$day->toDateString()] ?? 0.0;
                $amounts[] = $amount;
                $summary['amount'] += $amount;
                $summary['working_days']++;
            }

            $summary['amount'] = Money::round($summary['amount']);
            $byMonth[] = $summary;
        }

        return ['amounts' => $amounts, 'excluded' => $excluded, 'by_month' => $byMonth];
    }

    /**
     * Дневной ряд отгрузок Закреплённой базы работника за месяц.
     *
     * @return array<string, float>
     */
    private function dailySeries(int $managerId, CarbonImmutable $month, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $partners = array_keys($this->attribution->partnersOf($managerId, $month->endOfMonth()));
        $partners = $this->withoutNewPartners($partners, $month);

        if ($partners === []) {
            return [];
        }

        $ctx = AnalyticsContext::forScope($partners, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $series = $this->analytics->timeSeries($ctx, new AnalyticsFilters(
            dateFrom: $from->startOfDay(),
            dateTo: $to->endOfDay(),
        ));

        $byDay = [];
        foreach ($series['points'] as $point) {
            $byDay[(string) $point['period']] = (float) $point['amount'];
        }

        return $byDay;
    }

    /**
     * Партнёры без тех, кто в этом месяце находился в Периоде новизны (п. 6.3.3).
     *
     * @param  list<int>  $partnerIds
     * @return list<int>
     */
    private function withoutNewPartners(array $partnerIds, CarbonImmutable $month): array
    {
        if ($partnerIds === []) {
            return [];
        }

        $new = MotivationPartnerNovelty::query()
            ->whereIn('user_id', $partnerIds)
            ->newInMonth($month)
            ->pluck('user_id')
            ->map('intval')
            ->all();

        return array_values(array_diff($partnerIds, $new));
    }

    /**
     * Дни отсутствия работника в окне выборки.
     *
     * @return list<string>
     */
    private function absentDays(int $managerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ranges = ManagerAbsence::query()
            ->where('personal_manager_id', $managerId)
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->get(['starts_on', 'ends_on']);

        $days = [];

        foreach ($ranges as $range) {
            $rangeStart = CarbonImmutable::instance($range->starts_on)->startOfDay();
            $rangeEnd = CarbonImmutable::instance($range->ends_on)->startOfDay();

            for ($day = $rangeStart; $day->lte($rangeEnd); $day = $day->addDay()) {
                $days[] = $day->toDateString();
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * Половина перевыполнения предыдущего квартала (п. 5.4).
     *
     * Учитывается доля превышения, а не всё превышение: план, догоняющий факт
     * целиком, наказывает за удачный квартал и делает выгодным его не повторять.
     */
    private function overperformanceCarry(int $managerId, CarbonImmutable $start, float $share): float
    {
        $previous = $start->subQuarter();
        $plan = 0.0;
        $fact = 0.0;

        for ($i = 0; $i < 3; $i++) {
            $month = $previous->addMonths($i);
            $plan += (float) ($this->planValue($managerId, $month) ?? 0);
            $fact += $this->factValue($managerId, $month);
        }

        if ($plan <= 0) {
            return 0.0;
        }

        return max(0.0, $fact - $plan) * $share;
    }

    /**
     * Сопоставим ли план предыдущего квартала с расчётным.
     *
     * Предел снижения защищает от стратегии «провалить квартал ради лёгкого
     * плана» — то есть от падения плана, вызванного собственным результатом.
     * При переходе на новую методику падение вызвано сменой методики: прежние
     * планы ставились сверху вниз от цифры компании и расходятся с формульными
     * в полтора-два раза. Применить предел к ним значит отменить смену методики,
     * оставив прежние числа под новым названием.
     *
     * Поэтому предел работает только там, где есть с чем сравнивать: когда план
     * предыдущего квартала утверждён приказом этой же системы.
     */
    private function previousQuarterIsComparable(int $managerId, CarbonImmutable $start): bool
    {
        return \App\Models\Motivation\MotivationPlanOrder::query()
            ->forQuarter($start->subQuarter())
            ->where('personal_manager_id', $managerId)
            ->where('status', \App\Models\Motivation\MotivationPlanOrder::STATUS_APPROVED)
            ->exists();
    }

    private function previousQuarterPlan(int $managerId, CarbonImmutable $start): ?float
    {
        $previous = $start->subQuarter();
        $total = 0.0;
        $found = false;

        for ($i = 0; $i < 3; $i++) {
            $value = $this->planValue($managerId, $previous->addMonths($i));

            if ($value !== null) {
                $total += $value;
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    /**
     * @return array<string, float|null>
     */
    private function currentPlans(int $managerId, CarbonImmutable $start): array
    {
        $values = [];

        for ($i = 0; $i < 3; $i++) {
            $month = $start->addMonths($i);
            $values[$month->toDateString()] = $this->planValue($managerId, $month);
        }

        return $values;
    }

    private function planValue(int $managerId, CarbonImmutable $month): ?float
    {
        $plan = \App\Models\CrmSalesPlan::query()
            ->forPeriod($month)
            ->forManager($managerId)
            ->first();

        return $plan?->amountValue();
    }

    private function factValue(int $managerId, CarbonImmutable $month): float
    {
        $partners = array_keys($this->attribution->partnersOf($managerId, $month->endOfMonth()));

        if ($partners === []) {
            return 0.0;
        }

        $ctx = AnalyticsContext::forScope($partners, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return 0.0;
        }

        return round((float) $this->analytics->metrics($ctx, new AnalyticsFilters(
            dateFrom: $month->startOfMonth()->startOfDay(),
            dateTo: $month->endOfMonth()->endOfDay(),
        ))['total_amount'], 2);
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Существует ли работник с такой карточкой — для команд и мастера.
     */
    public function managerExists(int $managerId): bool
    {
        return PersonalManager::query()->whereKey($managerId)->exists();
    }
}
