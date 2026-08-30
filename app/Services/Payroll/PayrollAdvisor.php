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
 *
 * Порядок — по деньгам, но сначала то, что реально успеть: «добить план» с
 * прибавкой в 31 000 ₽ бесполезен, если для этого за один оставшийся день нужно
 * отгрузить два миллиона. Внутри достижимых наверх всплывает то, что двигает
 * сразу два показателя: отгрузка молчащему плановому клиенту прибавляет выручку
 * и тянет охват к следующей ступени, а ступень умножает всю премию целиком.
 */
class PayrollAdvisor
{
    private const MAX_INVOICE_ADVICE = 5;

    private const REVENUE_STEP = 100_000.0;

    /** Сколько молчащих клиентов просчитываем и сколько показываем. */
    private const MAX_CLIENT_ADVICE = 12;

    private const MAX_CLIENT_CARDS = 4;

    private const MAX_TOPUP_CARDS = 2;

    /** Во сколько раз менеджер способен ускориться против своего темпа. */
    private const SPRINT = 1.5;

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
            $this->clientSaleAdvice($params, $inputs, $current),
            $this->activeClientsAdvice($params, $inputs, $current),
            $this->clientTopUpAdvice($params, $inputs, $current),
            $this->invoiceAdvice($params, $inputs, $current, $today),
            $this->planGapAdvice($params, $inputs, $current),
            $this->revenueStepAdvice($params, $inputs, $current),
        );

        $advice = array_values(array_filter($advice, fn (array $row): bool => abs($row['gain']) >= 1.0));

        return $this->rank($advice, $inputs, $today);
    }

    /**
     * Порядок: сначала выполнимое, потом по деньгам, при равных — по числу
     * показателей, которые двигает совет.
     *
     * Выполнимость грубая намеренно: сравниваем требуемую выручку с тем, что
     * менеджер успевает при полуторном темпе за оставшиеся дни. Точность здесь
     * не нужна — нужно лишь не ставить первым то, чего не сделать до конца месяца.
     *
     * @param  list<array<string, mixed>>  $advice
     * @return list<array<string, mixed>>
     */
    private function rank(array $advice, PayrollInputs $inputs, CarbonImmutable $today): array
    {
        $passed = max(1, (int) ($inputs->workingDays['passed'] ?? 1));
        $left = max(0, (int) ($inputs->workingDays['left'] ?? 0));
        // Сегодняшний день в workingDays уже пройден, но отгрузить в него ещё
        // можно — иначе в последний день месяца достижимым не остаётся ничего.
        $ahead = max($left, $this->calendar->isWorkingDay($today) ? 1 : 0);
        $reachable = ($inputs->revenue / $passed) * $ahead * self::SPRINT;

        foreach ($advice as $index => $row) {
            $required = (float) ($row['required_revenue'] ?? 0.0);
            $advice[$index]['feasible'] = $required <= 0.01 || $required <= $reachable;
            $advice[$index]['affects'] = (array) ($row['affects'] ?? []);
        }

        usort($advice, function (array $a, array $b): int {
            return [$b['feasible'], $b['gain'], count($b['affects'])] <=> [$a['feasible'], $a['gain'], count($a['affects'])];
        });

        return array_values($advice);
    }

    /**
     * Отгрузка молчащему плановому клиенту — единственный ход, который бьёт дважды.
     *
     * Выручка растёт, и клиент попадает в охват; если он замыкает ступень
     * множителя, прибавка кратно больше своей суммы. Поэтому в подсказке
     * разделено, сколько дала сама отгрузка, а сколько — попадание в охват.
     *
     * @return list<array<string, mixed>>
     */
    private function clientSaleAdvice(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $inactive = array_values(array_filter(
            $inputs->plannedClients,
            fn (PlannedClientInput $c): bool => ! $c->isActive() && ($c->plan ?? 0) > 0,
        ));

        if ($inactive === []) {
            return [];
        }

        usort($inactive, fn (PlannedClientInput $a, PlannedClientInput $b): int => ($b->plan ?? 0) <=> ($a->plan ?? 0));
        $advice = [];

        foreach (array_slice($inactive, 0, self::MAX_CLIENT_ADVICE) as $client) {
            $amount = (float) $client->plan;

            $withSale = $inputs->with([
                'revenue' => $inputs->revenue + $amount,
                'planned_clients' => $this->activated($inputs, $client->id, $amount),
            ]);
            $revenueOnly = $inputs->with(['revenue' => $inputs->revenue + $amount]);

            $gain = $this->calculator->calculate($params, $withSale)->total - $current->total;
            $fromRevenue = $this->calculator->calculate($params, $revenueOnly)->total - $current->total;
            $fromCoverage = $gain - $fromRevenue;

            $advice[] = [
                'key' => 'client_sale:'.$client->id,
                'kind' => 'clients',
                'title' => sprintf('%s: отгрузить на %s', $client->name, Money::rub($amount)),
                'detail' => $fromCoverage >= 1.0
                    ? sprintf(
                        'План клиента на месяц, отгрузок пока нет. %s даст сама выручка, ещё %s — попадание клиента в охват.',
                        Money::rub($fromRevenue),
                        Money::rub($fromCoverage),
                    )
                    : 'План клиента на месяц, отгрузок пока нет. Он же добавится к охвату — ближе к следующей ступени множителя.',
                'gain' => round($gain, 2),
                'affects' => $fromCoverage >= 1.0 ? ['revenue', 'clients'] : ['revenue', 'clients'],
                'required_revenue' => $amount,
                'target' => ['type' => 'client', 'id' => $client->id],
            ];
        }

        usort($advice, fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);

        return array_slice($advice, 0, self::MAX_CLIENT_CARDS);
    }

    /**
     * Клиент купил, но меньше плана: добрать остаток — чистая выручка, охват уже есть.
     *
     * @return list<array<string, mixed>>
     */
    private function clientTopUpAdvice(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $under = array_values(array_filter(
            $inputs->plannedClients,
            fn (PlannedClientInput $c): bool => $c->isActive() && ($c->plan ?? 0) > 0 && $c->fact < (float) $c->plan,
        ));

        if ($under === []) {
            return [];
        }

        usort($under, fn (PlannedClientInput $a, PlannedClientInput $b): int => (($b->plan ?? 0) - $b->fact) <=> (($a->plan ?? 0) - $a->fact));
        $advice = [];

        foreach (array_slice($under, 0, self::MAX_TOPUP_CARDS) as $client) {
            $gap = (float) $client->plan - $client->fact;
            $simulated = $inputs->with(['revenue' => $inputs->revenue + $gap]);
            $gain = $this->calculator->calculate($params, $simulated)->total - $current->total;

            $advice[] = [
                'key' => 'client_topup:'.$client->id,
                'kind' => 'revenue',
                'title' => sprintf('%s: добрать %s до плана', $client->name, Money::rub($gap)),
                'detail' => sprintf(
                    'Отгружено %s из %s — %s плана. Клиент уже в охвате, прибавка идёт только выручкой.',
                    Money::rub($client->fact),
                    Money::rub((float) $client->plan),
                    Money::percent($client->fact / (float) $client->plan, 0),
                ),
                'gain' => round($gain, 2),
                'affects' => ['revenue'],
                'required_revenue' => $gap,
                'target' => ['type' => 'client', 'id' => $client->id],
            ];
        }

        return $advice;
    }

    /**
     * Копия списка плановых клиентов, где один помечен купившим.
     *
     * @return list<array<string, mixed>>
     */
    private function activated(PayrollInputs $inputs, int $clientId, float $amount): array
    {
        return array_map(
            fn (PlannedClientInput $c): array => $c->id === $clientId
                ? (new PlannedClientInput($c->id, $c->name, $c->plan, max($amount, 1.0)))->toArray()
                : $c->toArray(),
            $inputs->plannedClients,
        );
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
            'affects' => ['clients'],
            // Ступень берут продажами: требуемая выручка — планы тех, кого не хватает.
            'required_revenue' => array_sum(array_map(fn (PlannedClientInput $c): float => (float) ($c->plan ?? 0), array_slice($inactive, 0, $needed))),
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

        // Тот же горизонт, что и в прогнозе: советовать «поторопите оплату»
        // по долгу с весны бессмысленно — там уже работа с дебиторкой, а не
        // напоминание, и обещанная прибавка недостижима в этом месяце.
        $horizon = $today->subDays(max(0, (int) config('payroll.forecast.risk_overdue_days', 30)));
        $rows = array_values(array_filter(
            $inputs->atRiskInvoices,
            fn (InvoiceInput $i): bool => $i->dueOn !== null && CarbonImmutable::parse($i->dueOn)->greaterThanOrEqualTo($horizon),
        ));
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
                'affects' => ['penalty'],
                // Здесь ничего продавать не нужно — только добиться оплаты в срок.
                'required_revenue' => 0.0,
                'protective' => true,
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
            'affects' => ['revenue'],
            'required_revenue' => $gap,
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
            'affects' => ['revenue'],
            'required_revenue' => self::REVENUE_STEP,
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
