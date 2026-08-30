<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Components\Kpi\DisciplinePenaltyFactor;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;

/**
 * Прогноз дохода на конец месяца — три сценария тем же калькулятором.
 *
 * Никакой второй формулы: сценарий — это гипотетические входы (выручка по темпу,
 * клиенты, судьба неоплаченных накладных), пропущенные через тот же расчёт.
 * Кривая по дням — оценка для графика: прошлое — доля накопленной премии,
 * будущее — коридор между пессимистичным и оптимистичным сценариями.
 */
class PayrollForecaster
{
    /** Насколько «перевыполнить план» в сценарии-ориентире. */
    private const STRETCH_SHARE = 1.25;

    /**
     * Названия сценариев — от действия менеджера, а не от статистики.
     *
     * «Если ничего не изменится» читалось как «оставить как есть», хотя за этим
     * стояло «ни один долг не собран». Формулировка должна называть причину,
     * иначе цифра ниже текущей выглядит ошибкой расчёта.
     *
     * «Если долги не соберу» было прямой инверсией смысла: штраф начисляется в
     * месяц прихода денег, поэтому несобранный долг премию не трогает вовсе —
     * бьёт как раз собранный с опозданием. Название обязано совпадать с тем,
     * что подставлено в расчёт.
     *
     * @var array<string, string>
     */
    public const SCENARIO_LABELS = [
        'pessimistic' => 'Если просроченные оплаты придут разом',
        'base' => 'Если пойдёт как идёт',
        'optimistic' => 'Если закрою план и верну клиентов',
        'stretch' => 'Если перевыполню план на четверть',
        'perfect' => 'То же самое плюс ни одной просрочки',
    ];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly WorkingCalendar $calendar,
        private readonly DisciplinePenaltyFactor $penaltyFactor,
    ) {}

    /**
     * @return array{
     *   scenarios: array<string, array<string, mixed>>,
     *   curve: list<array<string, mixed>>,
     *   basis: array<string, mixed>
     * }
     */
    public function forecast(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $month = CarbonImmutable::parse($inputs->month)->startOfMonth();
        $days = $inputs->workingDays;
        $passed = max(0, (int) $days['passed']);
        $total = max(1, (int) $days['total']);
        $left = max(0, (int) $days['left']);
        $closed = $today->greaterThan($month->endOfMonth());

        $tiers = $this->penaltyFactor->tiers($params->for('kpi_bonus')['discipline_penalty'] ?? []);
        $lastWorkingDay = $this->lastWorkingDay($month);

        if ($closed || $passed === 0) {
            $snapshot = $this->scenario('base', $inputs, $current, $inputs, $today);
            $scenarios = [
                'pessimistic' => $snapshot + ['key' => 'pessimistic', 'label' => self::SCENARIO_LABELS['pessimistic']],
                'base' => $snapshot,
                'optimistic' => $snapshot + ['key' => 'optimistic', 'label' => self::SCENARIO_LABELS['optimistic']],
                'stretch' => $snapshot + ['key' => 'stretch', 'label' => self::SCENARIO_LABELS['stretch']],
                'perfect' => $snapshot + ['key' => 'perfect', 'label' => self::SCENARIO_LABELS['perfect']],
            ];
        } else {
            $rate = $inputs->revenue / $passed;

            // В сценарии участвуют только долги ближнего горизонта: те, что
            // могут прийти в этом месяце. Зависшая дебиторка сюда не попадает —
            // см. $this->risky() и config('payroll.forecast.risk_overdue_days').
            $risky = $this->risky($inputs, $today);

            $pessimistic = $inputs->with([
                'invoices' => $this->serialize(array_merge($inputs->invoices, $this->paidOnAll($risky, $lastWorkingDay))),
                'at_risk_invoices' => [],
            ]);

            // «Как идёт» — это и про долги тоже: висели и висят. Раньше базовый
            // сценарий подставлял их оплаченными сегодня, и прогноз оказывался
            // ниже уже заработанного при выполненном плане.
            $base = $inputs->with([
                'revenue' => round($rate * $total, 2),
                'planned_clients' => $this->serializeClients($this->grownClients($inputs, $passed, $total)),
                'at_risk_invoices' => [],
            ]);

            $optimistic = $inputs->with([
                'revenue' => round(max($rate * $total, (float) ($inputs->plan ?? 0.0)), 2),
                'planned_clients' => $this->serializeClients($this->allActive($inputs)),
                'at_risk_invoices' => [],
            ]);

            // Предел месяца: к оптимистичному добавляем отсутствие уже случившихся
            // задержек. Недостижимо задним числом — но именно эта разница и есть
            // цена просрочек, и без неё потолок выглядел бы как «135 тысяч и всё».
            // «А если перевыполню?» — тот же оптимистичный расклад, но выручка выше
            // плана. Уже начисленный штраф остаётся: перевыполнение его не отменяет.
            $stretch = $optimistic->with(['revenue' => round((float) ($inputs->plan ?? 0) * self::STRETCH_SHARE, 2)]);

            $perfect = $stretch->with(['invoices' => []]);

            $scenarios = [
                'pessimistic' => $this->scenario('pessimistic', $pessimistic, $this->calculator->calculate($params, $pessimistic), $inputs, $today),
                'base' => $this->scenario('base', $base, $this->calculator->calculate($params, $base), $inputs, $today),
                'optimistic' => $this->scenario('optimistic', $optimistic, $this->calculator->calculate($params, $optimistic), $inputs, $today),
                'stretch' => $this->scenario('stretch', $stretch, $this->calculator->calculate($params, $stretch), $inputs, $today),
                'perfect' => $this->scenario('perfect', $perfect, $this->calculator->calculate($params, $perfect), $inputs, $today),
            ];
        }

        return [
            'scenarios' => $scenarios,
            'current_total' => $current->total,
            'curve' => $this->curve($month, $today, $current, $scenarios),
            'basis' => [
                'working_days' => ['total' => $total, 'passed' => $passed, 'left' => $left],
                'revenue_per_day' => $passed > 0 ? round($inputs->revenue / $passed, 2) : null,
                'closed' => $closed,
                'at_risk_count' => count($this->risky($inputs, $today)),
                'at_risk_amount' => round(array_sum(array_map(fn (InvoiceInput $i): float => $i->amount, $this->risky($inputs, $today))), 2),
                'deferred_count' => count($this->deferred($inputs, $today)),
                'deferred_amount' => round(array_sum(array_map(fn (InvoiceInput $i): float => $i->amount, $this->deferred($inputs, $today))), 2),
                'risk_overdue_days' => (int) config('payroll.forecast.risk_overdue_days', 30),
                'tiers' => $tiers,
                'computed_for' => $today->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(string $key, PayrollInputs $inputs, PayrollBreakdown $breakdown, ?PayrollInputs $actual = null, ?CarbonImmutable $today = null): array
    {
        $kpi = $breakdown->component('kpi_bonus');

        return [
            'key' => $key,
            'label' => self::SCENARIO_LABELS[$key],
            'hint' => $this->hint($key, $actual ?? $inputs, $today),
            'total' => $breakdown->total,
            'kpi' => $breakdown->amountOf('kpi_bonus'),
            'revenue' => $inputs->revenue,
            'active_clients' => count($inputs->activeClients()),
            'planned_clients' => count($inputs->plannedClients),
            'penalty' => (float) ($kpi->meta['penalty'] ?? 0.0),
            'performance' => $kpi->meta['performance'] ?? null,
            'multiplier' => $kpi->meta['multiplier'] ?? null,
        ];
    }

    /**
     * Что именно предполагает сценарий — словами, которые менеджер может проверить.
     */
    private function hint(string $key, PayrollInputs $inputs, ?CarbonImmutable $today = null): string
    {
        $risky = $this->risky($inputs, $today ?? CarbonImmutable::now()->startOfDay());
        $risk = count($risky);
        $riskAmount = array_sum(array_map(fn (InvoiceInput $i): float => $i->amount, $risky));
        $planned = count($inputs->plannedClients);
        $active = count($inputs->activeClients());

        return match ($key) {
            'pessimistic' => $risk > 0
                ? sprintf('%d свежих долгов на %s приходят до конца месяца — штраф считается в месяц оплаты; новых продаж нет', $risk, Money::rub($riskAmount))
                : 'новых продаж до конца месяца нет',
            'base' => 'выручка растёт в том же темпе, что и с начала месяца, долги остаются как есть',
            'optimistic' => sprintf(
                'план закрыт, купили все %d плановых клиентов (сейчас %d), новых просрочек нет',
                $planned,
                $active,
            ),
            // Задним числом недостижимо: деньги уже пришли поздно. Но это предел
            // месяца, и разница с предыдущим сценарием — цена случившихся задержек.
            'stretch' => sprintf(
                'выручка на четверть выше плана (%s), клиенты на месте, новых просрочек нет',
                Money::rub(($inputs->plan ?? 0) * self::STRETCH_SHARE),
            ),
            'perfect' => 'предел месяца: к тому же ни одна оплата не пришла с опозданием',
            default => '',
        };
    }

    /**
     * Долги ближнего горизонта — те, чья оплата в этом месяце правдоподобна.
     *
     * Отсекаем зависшую дебиторку: накладную, просроченную на полгода, завтра
     * не оплатят, а её штраф (×3 от суммы) в сценарии съедал всю премию. Она
     * остаётся во входных данных и на экране просрочек — просто не участвует
     * в предсказании конца месяца.
     *
     * @return list<InvoiceInput>
     */
    private function risky(PayrollInputs $inputs, CarbonImmutable $today): array
    {
        $horizon = $today->subDays(max(0, (int) config('payroll.forecast.risk_overdue_days', 30)));

        return array_values(array_filter(
            $inputs->atRiskInvoices,
            fn (InvoiceInput $i): bool => $i->dueOn !== null && CarbonImmutable::parse($i->dueOn)->greaterThanOrEqualTo($horizon),
        ));
    }

    /**
     * Долги вне горизонта — показываем отдельно, чтобы молча их не прятать.
     *
     * @return list<InvoiceInput>
     */
    private function deferred(PayrollInputs $inputs, CarbonImmutable $today): array
    {
        $risky = $this->risky($inputs, $today);

        return array_values(array_filter($inputs->atRiskInvoices, fn (InvoiceInput $i): bool => ! in_array($i, $risky, true)));
    }

    /**
     * @param  list<InvoiceInput>  $invoices
     * @return list<InvoiceInput>
     */
    private function paidOnAll(array $invoices, CarbonImmutable $paidOn): array
    {
        return array_map(fn (InvoiceInput $i): InvoiceInput => $this->paidOn($i, $paidOn), $invoices);
    }

    private function paidOn(InvoiceInput $invoice, CarbonImmutable $paidOn): InvoiceInput
    {
        $due = $invoice->dueOn === null ? $paidOn : CarbonImmutable::parse($invoice->dueOn);
        $working = $paidOn->lessThanOrEqualTo($due) ? 0 : $this->calendar->workingDaysBetween($due, $paidOn);
        $calendar = $paidOn->lessThanOrEqualTo($due) ? 0 : (int) $due->diffInDays($paidOn);

        return $invoice->with([
            'settled_on' => $paidOn->toDateString(),
            'delay_working_days' => $working,
            'delay_calendar_days' => $calendar,
            'source' => 'forecast',
            'payment_status' => 'paid',
        ]);
    }

    /**
     * Активные клиенты растут в темпе месяца: сколько купило за прошедшие дни — столько же за оставшиеся.
     *
     * @return list<PlannedClientInput>
     */
    private function grownClients(PayrollInputs $inputs, int $passed, int $total): array
    {
        $active = count($inputs->activeClients());
        $expected = min(count($inputs->plannedClients), (int) round($active / max(1, $passed) * $total));
        $toActivate = max(0, $expected - $active);

        return $this->activate($inputs, $toActivate);
    }

    /**
     * @return list<PlannedClientInput>
     */
    private function allActive(PayrollInputs $inputs): array
    {
        return $this->activate($inputs, count($inputs->plannedClients));
    }

    /**
     * Пометить N неактивных плановых клиентов купившими (по убыванию плана — крупные вероятнее).
     *
     * @return list<PlannedClientInput>
     */
    public function activate(PayrollInputs $inputs, int $count): array
    {
        $rows = $inputs->plannedClients;
        usort($rows, fn (PlannedClientInput $a, PlannedClientInput $b): int => ($b->plan ?? 0) <=> ($a->plan ?? 0));

        $result = [];
        foreach ($rows as $client) {
            if (! $client->isActive() && $count > 0) {
                $result[] = new PlannedClientInput($client->id, $client->name, $client->plan, max(1.0, (float) ($client->plan ?? 1.0)));
                $count--;
            } else {
                $result[] = $client;
            }
        }

        return $result;
    }

    /**
     * Кривая по календарным дням: заработано (оценка) до сегодня, дальше — коридор сценариев.
     *
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return list<array<string, mixed>>
     */
    private function curve(CarbonImmutable $month, CarbonImmutable $today, PayrollBreakdown $current, array $scenarios): array
    {
        $fixed = $current->total - $current->amountOf('kpi_bonus');
        $kpiNow = $current->amountOf('kpi_bonus');
        $end = $month->endOfMonth()->startOfDay();
        $lastPast = $today->greaterThan($end) ? $end : $today;

        $workingSoFar = max(1, $this->calendar->monthDays($month, $lastPast)['passed']);
        $workingTotal = max(1, $this->calendar->monthDays($month, $end)['total']);

        $points = [];
        $counter = 0;

        for ($day = $month; $day->lte($end); $day = $day->addDay()) {
            if ($this->calendar->isWorkingDay($day)) {
                $counter++;
            }

            $isPast = $day->lte($lastPast);
            $shareNow = min(1.0, $counter / $workingSoFar);
            $shareEnd = $workingTotal > $workingSoFar
                ? max(0.0, ($counter - $workingSoFar) / ($workingTotal - $workingSoFar))
                : 1.0;

            $earned = $fixed + $kpiNow * $shareNow;

            $points[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d.m'),
                'earned' => $isPast ? round($earned, 2) : null,
                'low' => $isPast ? null : round($current->total + ($scenarios['pessimistic']['total'] - $current->total) * $shareEnd, 2),
                'base' => $isPast ? null : round($current->total + ($scenarios['base']['total'] - $current->total) * $shareEnd, 2),
                'high' => $isPast ? null : round($current->total + ($scenarios['optimistic']['total'] - $current->total) * $shareEnd, 2),
                'is_today' => $day->isSameDay($today),
            ];
        }

        // Точка «сегодня» одновременно последняя в прошлом и первая в будущем — чтобы линии сомкнулись.
        foreach ($points as $index => $point) {
            if ($point['is_today']) {
                $points[$index]['low'] = round($current->total, 2);
                $points[$index]['base'] = round($current->total, 2);
                $points[$index]['high'] = round($current->total, 2);
            }
        }

        return $points;
    }

    private function lastWorkingDay(CarbonImmutable $month): CarbonImmutable
    {
        $day = $month->endOfMonth()->startOfDay();

        while (! $this->calendar->isWorkingDay($day) && $day->gt($month)) {
            $day = $day->subDay();
        }

        return $day;
    }

    /**
     * @param  list<InvoiceInput>  $invoices
     * @return list<array<string, mixed>>
     */
    private function serialize(array $invoices): array
    {
        return array_map(fn (InvoiceInput $i): array => $i->toArray(), $invoices);
    }

    /**
     * @param  list<PlannedClientInput>  $clients
     * @return list<array<string, mixed>>
     */
    private function serializeClients(array $clients): array
    {
        return array_map(fn (PlannedClientInput $c): array => $c->toArray(), $clients);
    }
}
