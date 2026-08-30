<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Components\Kpi\DisciplinePenaltyFactor;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;
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
    /** @var array<string, string> */
    public const SCENARIO_LABELS = [
        'pessimistic' => 'Если ничего не изменится',
        'base' => 'При текущем темпе',
        'optimistic' => 'Если добить план и всех клиентов',
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
            $snapshot = $this->scenario('base', $inputs, $current);
            $scenarios = [
                'pessimistic' => $snapshot + ['key' => 'pessimistic', 'label' => self::SCENARIO_LABELS['pessimistic']],
                'base' => $snapshot,
                'optimistic' => $snapshot + ['key' => 'optimistic', 'label' => self::SCENARIO_LABELS['optimistic']],
            ];
        } else {
            $rate = $inputs->revenue / $passed;

            $pessimistic = $inputs->with([
                'invoices' => $this->serialize(array_merge($inputs->invoices, $this->atRiskPaidOn($inputs, $lastWorkingDay))),
                'at_risk_invoices' => [],
            ]);

            $base = $inputs->with([
                'revenue' => round($rate * $total, 2),
                'planned_clients' => $this->serializeClients($this->grownClients($inputs, $passed, $total)),
                'invoices' => $this->serialize(array_merge($inputs->invoices, $this->overdueAtRiskPaidOn($inputs, $today))),
                'at_risk_invoices' => [],
            ]);

            $optimistic = $inputs->with([
                'revenue' => round(max($rate * $total, (float) ($inputs->plan ?? 0.0)), 2),
                'planned_clients' => $this->serializeClients($this->allActive($inputs)),
                'at_risk_invoices' => [],
            ]);

            $scenarios = [
                'pessimistic' => $this->scenario('pessimistic', $pessimistic, $this->calculator->calculate($params, $pessimistic)),
                'base' => $this->scenario('base', $base, $this->calculator->calculate($params, $base)),
                'optimistic' => $this->scenario('optimistic', $optimistic, $this->calculator->calculate($params, $optimistic)),
            ];
        }

        return [
            'scenarios' => $scenarios,
            'curve' => $this->curve($month, $today, $current, $scenarios),
            'basis' => [
                'working_days' => ['total' => $total, 'passed' => $passed, 'left' => $left],
                'revenue_per_day' => $passed > 0 ? round($inputs->revenue / $passed, 2) : null,
                'closed' => $closed,
                'at_risk_count' => count($inputs->atRiskInvoices),
                'at_risk_amount' => round(array_sum(array_map(fn (InvoiceInput $i): float => $i->amount, $inputs->atRiskInvoices)), 2),
                'tiers' => $tiers,
                'computed_for' => $today->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(string $key, PayrollInputs $inputs, PayrollBreakdown $breakdown): array
    {
        $kpi = $breakdown->component('kpi_bonus');

        return [
            'key' => $key,
            'label' => self::SCENARIO_LABELS[$key],
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
     * Все неоплаченные накладные месяца оплачены в последний рабочий день — худший разумный исход.
     *
     * @return list<InvoiceInput>
     */
    private function atRiskPaidOn(PayrollInputs $inputs, CarbonImmutable $paidOn): array
    {
        return array_map(fn (InvoiceInput $i): InvoiceInput => $this->paidOn($i, $paidOn), $inputs->atRiskInvoices);
    }

    /**
     * Накладные с уже прошедшим сроком оплачены сегодня; остальные — в срок.
     *
     * @return list<InvoiceInput>
     */
    private function overdueAtRiskPaidOn(PayrollInputs $inputs, CarbonImmutable $today): array
    {
        $rows = [];

        foreach ($inputs->atRiskInvoices as $invoice) {
            if ($invoice->dueOn !== null && CarbonImmutable::parse($invoice->dueOn)->lessThan($today)) {
                $rows[] = $this->paidOn($invoice, $today);
            }
        }

        return $rows;
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
