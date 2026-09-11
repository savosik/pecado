<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\PayrollInvoiceSettlement;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * База начисления вычета К1: Σ (остаток просроченной задолженности × дни просрочки).
 *
 * Считается по дням, а не по остатку на конец периода: остаток на конец наказывает
 * за долги, которые работник как раз собрал.
 *
 * **Сколько не оплачено — говорит регистр взаиморасчётов 1С**, как и лестнице долга:
 * 1С сама разносит оплаты по документам, и её остаток по документу — истина
 * (`shipments.paid_amount` — проекция плановых строк регистра). Мост накладных
 * `payroll_invoice_settlements` нужен только за двумя вещами, которых в регистре нет:
 * срок оплаты документа и **даты** найденных платежей. До 11.09.2026 остаток брался
 * из самого моста, и всё, что мост не сопоставил (зачёты, платежи без ссылки на
 * реализацию, авансы по чужому заказу), становилось «долгом»: у Курочкиной за сентябрь
 * это дало 1,15 млн ₽ «просрочки» при 112 тыс ₽ по регистру.
 *
 * Правила, по которым считается остаток документа на день d:
 *   остаток(d) = min(сумма, остаток_по_регистру_сейчас + платежи моста с датой позже d)
 * — то есть платёж, дату которого мост нашёл, закрывает долг со своего дня, а оплата,
 * которую 1С видит, а мост датировать не смог, считается состоявшейся до начала
 * периода: незнание трактуется в пользу работника, как в «Моей зарплате». Ручная дата
 * руководителя датирует именно эту недатированную часть.
 *
 * Документ без графика оплаты в регистре в вычет не идёт: у 1С нет о нём мнения,
 * а «просрочен» на пустом месте — ложь (так же считает лестница долга).
 *
 * Потолок: просрочка партнёра в любой день не больше его долга по ленте фактов
 * регистра на этот день. Это страховка от расхождения моста и регистра — долг,
 * которого у партнёра нет, в вычет не попадёт ни при каком сопоставлении.
 *
 * Текущий месяц считается только до сегодняшнего дня: будущие дни не начисляются.
 * Просрочка начинается после льготного периода (п. 2.17) и прекращается в день
 * оплаты (п. 6.5.2); долг, выведенный руководителем из базы, не начисляется.
 */
class OverdueDebtIntegrator
{
    public function __construct(private readonly WorkingCalendar $calendar) {}

    /**
     * @param  list<int>  $partnerIds
     * @param  array<int, string>  $names  partner_id → отображаемое имя
     * @return array{integral: float, rows: list<array<string, mixed>>, excluded: list<array<string, mixed>>, as_of: string|null}
     */
    public function forMonth(array $partnerIds, CarbonInterface $month, array $names = [], int $graceWorkingDays = 5, ?CarbonInterface $today = null): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $monthEnd = $period->endOfMonth()->startOfDay();
        $todayDay = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();
        $asOf = $monthEnd->lessThan($todayDay) ? $monthEnd : $todayDay;

        if ($partnerIds === [] || $asOf->lessThan($period)) {
            return ['integral' => 0.0, 'rows' => [], 'excluded' => [], 'as_of' => $partnerIds === [] ? null : $asOf->toDateString()];
        }

        $invoices = $this->candidates($partnerIds, $period, $asOf);
        $exclusions = $this->exclusions($partnerIds, $period, $asOf);
        $debts = $this->ledgerDebts($partnerIds, $period, $asOf);

        // Проход 1: сырой остаток каждого документа по дням и сумма по партнёру на день.
        $prepared = [];
        $partnerDay = [];

        foreach ($invoices as $invoice) {
            $item = $this->prepare($invoice, $period, $asOf, $graceWorkingDays, $exclusions);

            if ($item === null) {
                continue;
            }

            foreach ($item['daily'] as $day => $balance) {
                $partnerDay[$item['partner_id']][$day] = ($partnerDay[$item['partner_id']][$day] ?? 0.0) + $balance;
            }

            $prepared[] = $item;
        }

        // Проход 2: потолок по долгу партнёра из регистра и итоговые строки.
        $integral = 0.0;
        $rows = [];
        $excluded = [];

        foreach ($prepared as $item) {
            $partnerId = $item['partner_id'];
            $sum = 0.0;
            $days = 0;

            foreach ($item['daily'] as $day => $balance) {
                $value = $balance * $this->factor($partnerDay[$partnerId][$day] ?? 0.0, $debts[$partnerId][$day] ?? 0.0);

                if ($value > SettlementEntry::EPSILON) {
                    $sum += $value;
                    $days++;
                }
            }

            $asOfKey = $asOf->toDateString();
            $balanceEnd = $item['balance_end'] * $this->factor($partnerDay[$partnerId][$asOfKey] ?? $item['balance_end'], $debts[$partnerId][$asOfKey] ?? 0.0);

            /** @var PayrollInvoiceSettlement $invoice */
            $invoice = $item['invoice'];

            $row = [
                'invoice_id' => (int) $invoice->getKey(),
                'shipment_id' => (int) $invoice->shipment_id,
                'number' => $invoice->erp_number,
                'partner_id' => $partnerId,
                'partner_name' => $names[$partnerId] ?? '',
                'amount' => (float) $invoice->total_amount,
                'registry_paid' => Money::round($item['registry_paid']),
                'shipped_on' => $invoice->shipped_on?->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                // «Заплатить был должен» — срок плюс льготные рабочие дни: с этого дня идёт начисление.
                'grace_ends_on' => $item['grace_ends_on'],
                'settled_on' => $invoice->settled_on?->toDateString(),
                'days' => $days,
                'integral' => Money::round($sum),
                // Долг по документу на дату расчёта — в рублях, с учётом потолка по регистру.
                'balance_end' => Money::round($balanceEnd),
                'as_of' => $asOfKey,
                'needs_review' => (bool) $invoice->needs_review,
            ];

            if ($item['excluded_days'] > 0) {
                $row['excluded_days'] = $item['excluded_days'];
                $row['exclusion_reason'] = $item['exclusion_reason'];
                $excluded[] = $row;
            }

            if ($row['integral'] <= 0) {
                continue;
            }

            $integral += $row['integral'];
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $b['integral'] <=> $a['integral']);

        return ['integral' => Money::round($integral), 'rows' => $rows, 'excluded' => $excluded, 'as_of' => $asOf->toDateString()];
    }

    /**
     * Документы, которые могли быть просрочены в периоде: срок из графика регистра
     * наступил, и долг по ним либо есть сейчас, либо был закрыт не раньше начала периода.
     *
     * Без ORDER BY: выборка тянет json-колонку с платежами, а сортировка по ней
     * валила MySQL «Out of sort memory».
     *
     * @param  list<int>  $partnerIds
     * @return \Illuminate\Support\Collection<int, PayrollInvoiceSettlement>
     */
    private function candidates(array $partnerIds, CarbonImmutable $period, CarbonImmutable $asOf): \Illuminate\Support\Collection
    {
        return PayrollInvoiceSettlement::query()
            ->join('shipments', 'shipments.id', '=', 'payroll_invoice_settlements.shipment_id')
            ->whereIn('payroll_invoice_settlements.user_id', $partnerIds)
            ->where('payroll_invoice_settlements.due_source', PayrollInvoiceSettlement::DUE_SCHEDULE)
            ->whereNotNull('payroll_invoice_settlements.due_on')
            ->whereDate('payroll_invoice_settlements.due_on', '<=', $asOf)
            ->where(fn ($q) => $q->where('shipments.payment_status', '<>', Shipment::PAYMENT_EXCLUDED)->orWhereNull('shipments.payment_status'))
            ->where(fn ($q) => $q
                ->whereRaw('shipments.total_amount - COALESCE(shipments.paid_amount, 0) > ?', [SettlementEntry::EPSILON])
                ->orWhereNull('payroll_invoice_settlements.settled_on')
                ->orWhereDate('payroll_invoice_settlements.settled_on', '>=', $period))
            ->get([
                'payroll_invoice_settlements.id', 'payroll_invoice_settlements.shipment_id', 'payroll_invoice_settlements.user_id',
                'payroll_invoice_settlements.erp_number', 'payroll_invoice_settlements.total_amount', 'payroll_invoice_settlements.shipped_on',
                'payroll_invoice_settlements.due_on', 'payroll_invoice_settlements.settled_on', 'payroll_invoice_settlements.settled_source',
                'payroll_invoice_settlements.payments', 'payroll_invoice_settlements.needs_review',
                'shipments.paid_amount as registry_paid_amount',
            ]);
    }

    /**
     * Сырой остаток документа по дням просрочки внутри периода (без потолка).
     *
     * @param  list<array{shipment_id: int|null, from: CarbonImmutable, until: CarbonImmutable|null, reason: string}>  $exclusions
     * @return array{invoice: PayrollInvoiceSettlement, partner_id: int, daily: array<string, float>, balance_end: float, registry_paid: float, grace_ends_on: string, excluded_days: int, exclusion_reason: string|null}|null
     */
    private function prepare(PayrollInvoiceSettlement $invoice, CarbonImmutable $period, CarbonImmutable $asOf, int $graceWorkingDays, array $exclusions): ?array
    {
        $due = $invoice->due_on === null ? null : CarbonImmutable::instance($invoice->due_on)->startOfDay();

        if ($due === null) {
            return null;
        }

        $total = (float) $invoice->total_amount;
        $registryPaid = min($total, max(0.0, (float) ($invoice->getAttribute('registry_paid_amount') ?? 0)));
        $remainingNow = max(0.0, $total - $registryPaid);
        $payments = $this->payments($invoice, $registryPaid);

        $overdueFrom = $this->graceEnd($due, $graceWorkingDays)->addDay();
        $from = $overdueFrom->greaterThan($period) ? $overdueFrom : $period;

        $daily = [];
        $excludedDays = 0;
        $exclusionReason = null;

        for ($day = $from; $day->lte($asOf); $day = $day->addDay()) {
            $balance = $this->balanceOn($day, $total, $remainingNow, $payments);

            if ($balance <= SettlementEntry::EPSILON) {
                continue;
            }

            $reason = $this->exclusionReason($exclusions, (int) ($invoice->shipment_id ?? 0), $day);

            if ($reason !== null) {
                $excludedDays++;
                $exclusionReason ??= $reason;

                continue;
            }

            $daily[$day->toDateString()] = $balance;
        }

        if ($daily === [] && $excludedDays === 0) {
            return null;
        }

        return [
            'invoice' => $invoice,
            'partner_id' => (int) $invoice->user_id,
            'daily' => $daily,
            'balance_end' => $this->balanceOn($asOf, $total, $remainingNow, $payments),
            'registry_paid' => $registryPaid,
            'grace_ends_on' => $overdueFrom->subDay()->toDateString(),
            'excluded_days' => $excludedDays,
            'exclusion_reason' => $exclusionReason,
        ];
    }

    /**
     * Остаток документа на конец дня: долг по регистру сейчас плюс платежи, которые
     * придут позже этого дня. День оплаты не начисляется — платёж этого дня уже учтён.
     *
     * @param  list<array{date: CarbonImmutable, amount: float}>  $payments
     */
    private function balanceOn(CarbonImmutable $day, float $total, float $remainingNow, array $payments): float
    {
        $later = 0.0;
        foreach ($payments as $payment) {
            if ($payment['date']->greaterThan($day)) {
                $later += $payment['amount'];
            }
        }

        return min($total, $remainingNow + $later);
    }

    /**
     * Датированные платежи: найденные мостом плюс ручная дата руководителя для
     * той части оплаты, которую 1С видит, а мост датировать не смог.
     *
     * @return list<array{date: CarbonImmutable, amount: float}>
     */
    private function payments(PayrollInvoiceSettlement $invoice, float $registryPaid): array
    {
        $rows = [];
        $dated = 0.0;

        foreach ((array) ($invoice->payments ?? []) as $payment) {
            if (! isset($payment['date'])) {
                continue;
            }

            $amount = (float) ($payment['amount'] ?? 0);
            $rows[] = ['date' => CarbonImmutable::parse((string) $payment['date'])->startOfDay(), 'amount' => $amount];
            $dated += $amount;
        }

        if ($invoice->settled_source === PayrollInvoiceSettlement::SOURCE_MANUAL && $invoice->settled_on !== null) {
            $undated = max(0.0, $registryPaid - $dated);
            if ($undated > SettlementEntry::EPSILON) {
                $rows[] = ['date' => CarbonImmutable::instance($invoice->settled_on)->startOfDay(), 'amount' => $undated];
            }
        }

        return $rows;
    }

    /**
     * Долг партнёра по ленте фактов регистра на каждый день периода, ₽.
     * Аванс (положительное сальдо) долгом не считается.
     *
     * @param  list<int>  $partnerIds
     * @return array<int, array<string, float>> partner_id → Y-m-d → долг
     */
    private function ledgerDebts(array $partnerIds, CarbonImmutable $period, CarbonImmutable $asOf): array
    {
        $opening = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('user_id', $partnerIds)
            ->whereDate('date', '<', $period)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(COALESCE(amount_rub, amount)) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        $deltas = [];
        foreach (DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('user_id', $partnerIds)
            ->whereDate('date', '>=', $period)
            ->whereDate('date', '<=', $asOf)
            ->groupBy('user_id', 'date')
            ->selectRaw('user_id, date, SUM(COALESCE(amount_rub, amount)) as total')
            ->get() as $row) {
            $deltas[(int) $row->user_id][CarbonImmutable::parse((string) $row->date)->toDateString()] = (float) $row->total;
        }

        $result = [];
        foreach ($partnerIds as $partnerId) {
            $balance = (float) ($opening[$partnerId] ?? 0.0);
            for ($day = $period; $day->lte($asOf); $day = $day->addDay()) {
                $key = $day->toDateString();
                $balance += $deltas[$partnerId][$key] ?? 0.0;
                $result[$partnerId][$key] = max(0.0, -1 * $balance);
            }
        }

        return $result;
    }

    /**
     * Доля, в которой документы партнёра идут в базу в этот день: не больше его долга.
     */
    private function factor(float $documentsSum, float $ledgerDebt): float
    {
        if ($documentsSum <= SettlementEntry::EPSILON) {
            return 1.0;
        }

        return min(1.0, max(0.0, $ledgerDebt) / $documentsSum);
    }

    /**
     * Последний день льготного периода: срок оплаты плюс рабочие дни.
     */
    private function graceEnd(CarbonImmutable $due, int $graceWorkingDays): CarbonImmutable
    {
        $day = $due;

        for ($counted = 0; $counted < $graceWorkingDays;) {
            $day = $day->addDay();

            if ($this->calendar->isWorkingDay($day)) {
                $counted++;
            }
        }

        return $day;
    }

    /**
     * Действующие в периоде исключения задолженности.
     *
     * @param  list<int>  $partnerIds
     * @return list<array{shipment_id: int|null, from: CarbonImmutable, until: CarbonImmutable|null, reason: string}>
     */
    private function exclusions(array $partnerIds, CarbonImmutable $period, CarbonImmutable $asOf): array
    {
        return MotivationDebtExclusion::query()
            ->whereIn('user_id', $partnerIds)
            ->whereDate('excluded_from', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('excluded_until')->orWhereDate('excluded_until', '>=', $period))
            ->get(['shipment_id', 'excluded_from', 'excluded_until', 'reason'])
            ->map(fn (MotivationDebtExclusion $row): array => [
                'shipment_id' => $row->shipment_id === null ? null : (int) $row->shipment_id,
                'from' => CarbonImmutable::instance($row->excluded_from)->startOfDay(),
                'until' => $row->excluded_until === null ? null : CarbonImmutable::instance($row->excluded_until)->startOfDay(),
                'reason' => (string) $row->reason,
            ])
            ->all();
    }

    /**
     * Основание, по которому день выведен из базы начисления; null — день считается.
     *
     * @param  list<array{shipment_id: int|null, from: CarbonImmutable, until: CarbonImmutable|null, reason: string}>  $exclusions
     */
    private function exclusionReason(array $exclusions, int $shipmentId, CarbonImmutable $day): ?string
    {
        foreach ($exclusions as $exclusion) {
            // shipment_id = null — исключение по партнёру целиком.
            if ($exclusion['shipment_id'] !== null && $exclusion['shipment_id'] !== $shipmentId) {
                continue;
            }

            if ($day->lt($exclusion['from'])) {
                continue;
            }

            if ($exclusion['until'] !== null && $day->gt($exclusion['until'])) {
                continue;
            }

            return $exclusion['reason'];
        }

        return null;
    }
}
