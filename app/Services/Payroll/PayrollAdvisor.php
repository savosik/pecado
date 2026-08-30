<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Components\Kpi\DisciplinePenaltyFactor;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\MonthLabel;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;

/**
 * Советы «как поднять»: каждый — пересчёт тем же калькулятором с одной правкой входов.
 *
 * Выигрыш в рублях считается, а не оценивается: совет без цифры — не совет.
 * Сортировка по выигрышу, нулевые отбрасываются.
 */
class PayrollAdvisor
{
    private const MAX_INVOICE_ADVICE = 5;

    private const REVENUE_STEP = 100_000.0;

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PayrollForecaster $forecaster,
        private readonly WorkingCalendar $calendar,
        private readonly DisciplinePenaltyFactor $penaltyFactor,
    ) {}

    /**
     * @return list<array{key: string, kind: string, title: string, detail: string, gain: float, target: array<string, mixed>|null}>
     */
    public function advise(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $month = CarbonImmutable::parse($inputs->month)->startOfMonth();

        if ($today->greaterThan($month->endOfMonth()) || ($inputs->plan === null || $inputs->plan <= 0)) {
            return [];
        }

        $advice = array_merge(
            $this->activeClientsAdvice($params, $inputs, $current),
            $this->invoiceAdvice($params, $inputs, $current, $today),
            $this->planGapAdvice($params, $inputs, $current),
            $this->revenueStepAdvice($params, $inputs, $current),
        );

        $advice = array_values(array_filter($advice, fn (array $row): bool => abs($row['gain']) >= 1.0));
        usort($advice, fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);

        return $advice;
    }

    /**
     * Сколько плановых клиентов не хватает до следующей ступени множителя.
     *
     * @return list<array<string, mixed>>
     */
    private function activeClientsAdvice(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $multiplier = $this->factor($current, 'active_clients');
        $next = $multiplier['meta']['next_step'] ?? null;

        if ($next === null) {
            return [];
        }

        $needed = (int) $next['clients_needed'];
        $simulated = $inputs->with([
            'planned_clients' => array_map(fn (PlannedClientInput $c): array => $c->toArray(), $this->forecaster->activate($inputs, $needed)),
        ]);
        $gain = $this->calculator->calculate($params, $simulated)->total - $current->total;

        $inactive = array_values(array_filter($inputs->plannedClients, fn (PlannedClientInput $c): bool => ! $c->isActive()));
        usort($inactive, fn (PlannedClientInput $a, PlannedClientInput $b): int => ($b->plan ?? 0) <=> ($a->plan ?? 0));

        return [[
            'key' => 'active_clients',
            'kind' => 'clients',
            'title' => sprintf(
                'Ещё %d %s с отгрузкой — множитель %s',
                $needed,
                $this->plural($needed, 'плановый клиент', 'плановых клиента', 'плановых клиентов'),
                Money::factor((float) $next['multiplier']),
            ),
            'detail' => sprintf(
                'Сейчас активных %d из %d. Порог ступени — %s. Кого ещё нет: %s',
                $multiplier['meta']['active'] ?? 0,
                $multiplier['meta']['planned'] ?? 0,
                Money::percent((float) $next['from_share'], 0),
                implode(', ', array_map(fn (PlannedClientInput $c): string => $c->name, array_slice($inactive, 0, 5))).(count($inactive) > 5 ? sprintf(' и ещё %d', count($inactive) - 5) : ''),
            ),
            'gain' => round($gain, 2),
            'target' => ['type' => 'clients', 'ids' => array_map(fn (PlannedClientInput $c): int => $c->id, array_slice($inactive, 0, $needed))],
        ]];
    }

    /**
     * Неоплаченные накладные: сколько стоит каждая, если оплата придёт с задержкой.
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceAdvice(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current, CarbonImmutable $today): array
    {
        $tiers = $this->penaltyFactor->tiers($params->for('kpi_bonus')['discipline_penalty'] ?? []);
        $worstTier = $tiers === [] ? null : $tiers[count($tiers) - 1];
        $firstTier = $tiers[0] ?? null;

        if ($worstTier === null || $firstTier === null) {
            return [];
        }

        $rows = $inputs->atRiskInvoices;
        usort($rows, fn (InvoiceInput $a, InvoiceInput $b): int => $b->amount <=> $a->amount);

        $advice = [];

        foreach (array_slice($rows, 0, self::MAX_INVOICE_ADVICE) as $invoice) {
            if ($invoice->dueOn === null) {
                continue;
            }

            $due = CarbonImmutable::parse($invoice->dueOn);
            $overdueDays = $due->lessThan($today) ? $this->calendar->workingDaysBetween($due, $today) : 0;

            // Что будет, если оплата придёт с задержкой худшей ступени.
            $late = $invoice->with([
                'settled_on' => $today->toDateString(),
                'delay_working_days' => max($overdueDays, (int) $worstTier['from_days']),
                'delay_calendar_days' => max($overdueDays, (int) $worstTier['from_days']),
                'source' => 'forecast',
                'payment_status' => 'paid',
            ]);
            $simulated = $inputs->with([
                'invoices' => array_map(fn (InvoiceInput $i): array => $i->toArray(), array_merge($inputs->invoices, [$late])),
            ]);
            $loss = $current->total - $this->calculator->calculate($params, $simulated)->total;

            if ($loss < 1.0) {
                continue;
            }

            $deadline = $this->deadline($due, (int) $firstTier['from_days'] - 1);
            $alreadyInPenalty = $overdueDays >= (int) $firstTier['from_days'];

            $advice[] = [
                'key' => 'invoice:'.$invoice->shipmentId,
                'kind' => 'invoice',
                'title' => $alreadyInPenalty
                    ? sprintf('%s: оплата уже с задержкой %d раб. дн. — каждый день дороже', $invoice->erpNumber ?? 'Накладная', $overdueDays)
                    : sprintf('%s: оплата до %s — без штрафа', $invoice->erpNumber ?? 'Накладная', MonthLabel::day($deadline)),
                'detail' => sprintf(
                    '%s, %s, срок %s. Если оплата придёт с задержкой от %d раб. дн., премия потеряет %s.',
                    $invoice->partnerName,
                    Money::rub($invoice->amount),
                    MonthLabel::day($due),
                    (int) $worstTier['from_days'],
                    Money::rub($loss),
                ),
                'gain' => round($loss, 2),
                'target' => ['type' => 'invoice', 'shipment_id' => $invoice->shipmentId, 'partner_id' => $invoice->partnerId],
            ];
        }

        return $advice;
    }

    /**
     * До 100 % плана: сколько не хватает и что это даст.
     *
     * @return list<array<string, mixed>>
     */
    private function planGapAdvice(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $plan = (float) $inputs->plan;

        if ($inputs->revenue >= $plan) {
            return [];
        }

        $simulated = $inputs->with(['revenue' => $plan]);
        $gain = $this->calculator->calculate($params, $simulated)->total - $current->total;
        $gap = $plan - $inputs->revenue;
        $left = (int) $inputs->workingDays['left'];

        return [[
            'key' => 'plan_gap',
            'kind' => 'revenue',
            'title' => sprintf('Добить план: ещё %s реализаций', Money::rub($gap)),
            'detail' => $left > 0
                ? sprintf('Это %s в рабочий день на оставшиеся %d %s.', Money::rub($gap / $left), $left, $this->plural($left, 'день', 'дня', 'дней'))
                : 'Рабочих дней в месяце не осталось.',
            'gain' => round($gain, 2),
            'target' => null,
        ]];
    }

    /**
     * Цена следующих 100 000 ₽ выручки — универсальный рычаг, пока не упёрлись в потолок.
     *
     * @return list<array<string, mixed>>
     */
    private function revenueStepAdvice(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $kpi = $current->component('kpi_bonus');

        if ($kpi === null || (bool) ($kpi->meta['capped'] ?? false)) {
            return [];
        }

        $simulated = $inputs->with(['revenue' => $inputs->revenue + self::REVENUE_STEP]);
        $gain = $this->calculator->calculate($params, $simulated)->total - $current->total;

        return [[
            'key' => 'revenue_step',
            'kind' => 'revenue',
            'title' => sprintf('Каждые %s реализаций — +%s к премии', Money::rub(self::REVENUE_STEP), Money::rub($gain)),
            'detail' => sprintf(
                'При текущем множителе %s и штрафе %s. Потолок премии — %s.',
                Money::factor((float) ($kpi->meta['multiplier'] ?? 1.0)),
                Money::rub((float) ($kpi->meta['penalty'] ?? 0.0)),
                Money::rub((float) ($kpi->meta['max_amount'] ?? 0.0)),
            ),
            'gain' => round($gain, 2),
            'target' => null,
        ]];
    }

    /**
     * Последний день, когда оплата ещё не считается задержкой: срок + льготные рабочие дни.
     */
    private function deadline(CarbonImmutable $due, int $graceWorkingDays): CarbonImmutable
    {
        $day = $due;
        $counted = 0;

        while ($counted < $graceWorkingDays) {
            $day = $day->addDay();
            if ($this->calendar->isWorkingDay($day)) {
                $counted++;
            }
        }

        return $day;
    }

    /**
     * @return array<string, mixed>
     */
    private function factor(PayrollBreakdown $breakdown, string $key): array
    {
        $kpi = $breakdown->component('kpi_bonus');

        foreach ($kpi === null ? [] : $kpi->children as $child) {
            if ($child->key === $key) {
                return $child->toArray();
            }
        }

        return ['meta' => []];
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $tail = $n % 10;
        $teen = $n % 100 >= 11 && $n % 100 <= 14;

        if (! $teen && $tail === 1) {
            return $one;
        }
        if (! $teen && $tail >= 2 && $tail <= 4) {
            return $few;
        }

        return $many;
    }
}
