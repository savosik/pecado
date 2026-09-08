<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\PayrollInvoiceSettlement;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * База начисления вычета К1: Σ (остаток просроченной задолженности × дни просрочки).
 *
 * Считается по дням, а не по остатку на конец периода. Разница не косметическая:
 * на августовских данных грубая оценка «остаток на конец × 30 дней» давала 44 640 ₽
 * там, где честный интеграл даёт 27 098 ₽. Остаток на конец периода наказывает
 * за долги, которые работник как раз собрал, — то есть ровно за ту работу,
 * ради которой показатель введён.
 *
 * Источник дат и платежей — мост накладных payroll_invoice_settlements: только он
 * знает срок оплаты по документу и даты каждого платежа. Регистр взаиморасчётов
 * даёт снимок задолженности, а для интеграла нужен баланс на каждый день.
 *
 * Просрочка начинается после льготного периода (п. 2.17) и прекращается в день
 * оплаты (п. 6.5.2). Долг, выведенный руководителем из базы начисления
 * (списан, передан в претензионную работу, оспаривается), в интеграл не входит.
 */
class OverdueDebtIntegrator
{
    public function __construct(private readonly WorkingCalendar $calendar) {}

    /**
     * @param  list<int>  $partnerIds
     * @param  array<int, string>  $names  partner_id → отображаемое имя
     * @return array{integral: float, rows: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    public function forMonth(array $partnerIds, CarbonInterface $month, array $names = [], int $graceWorkingDays = 5): array
    {
        if ($partnerIds === []) {
            return ['integral' => 0.0, 'rows' => [], 'excluded' => []];
        }

        $period = CarbonImmutable::instance($month)->startOfMonth();
        $monthEnd = $period->endOfMonth()->startOfDay();

        // Накладные, срок которых наступил не позже конца месяца и которые
        // не были закрыты до его начала. Без ORDER BY: выборка тянет json-колонку
        // с платежами, а сортировка по ней валила MySQL «Out of sort memory».
        $invoices = PayrollInvoiceSettlement::query()
            ->whereIn('user_id', $partnerIds)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $monthEnd)
            ->where(fn ($q) => $q->whereNull('settled_on')->orWhereDate('settled_on', '>=', $period))
            ->get(['id', 'shipment_id', 'user_id', 'erp_number', 'total_amount', 'due_on', 'settled_on', 'payments']);

        $exclusions = $this->exclusions($partnerIds, $period, $monthEnd);

        $integral = 0.0;
        $rows = [];
        $excluded = [];

        foreach ($invoices as $invoice) {
            $result = $this->integrate($invoice, $period, $monthEnd, $graceWorkingDays, $exclusions);

            if ($result === null) {
                continue;
            }

            $row = [
                'invoice_id' => (int) $invoice->getKey(),
                'shipment_id' => (int) $invoice->shipment_id,
                'number' => $invoice->erp_number,
                'partner_id' => (int) $invoice->user_id,
                'partner_name' => $names[(int) $invoice->user_id] ?? '',
                'amount' => (float) $invoice->total_amount,
                'due_on' => $invoice->due_on?->toDateString(),
                'settled_on' => $invoice->settled_on?->toDateString(),
                'days' => $result['days'],
                'integral' => $result['integral'],
                'balance_end' => $result['balance_end'],
            ];

            if ($result['excluded_days'] > 0) {
                $row['excluded_days'] = $result['excluded_days'];
                $row['exclusion_reason'] = $result['exclusion_reason'];
                $excluded[] = $row;
            }

            if ($result['integral'] <= 0) {
                continue;
            }

            $integral += $result['integral'];
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $b['integral'] <=> $a['integral']);

        return ['integral' => Money::round($integral), 'rows' => $rows, 'excluded' => $excluded];
    }

    /**
     * Интеграл по одной накладной внутри месяца.
     *
     * @param  list<array{shipment_id: int|null, from: CarbonImmutable, until: CarbonImmutable|null, reason: string}>  $exclusions
     * @return array{days: int, integral: float, balance_end: float, excluded_days: int, exclusion_reason: string|null}|null
     */
    private function integrate(
        PayrollInvoiceSettlement $invoice,
        CarbonImmutable $period,
        CarbonImmutable $monthEnd,
        int $graceWorkingDays,
        array $exclusions,
    ): ?array {
        $due = $invoice->due_on === null ? null : CarbonImmutable::instance($invoice->due_on)->startOfDay();

        if ($due === null) {
            return null;
        }

        $overdueFrom = $this->graceEnd($due, $graceWorkingDays)->addDay();
        $settled = $invoice->settled_on === null ? null : CarbonImmutable::instance($invoice->settled_on)->startOfDay();

        $from = $overdueFrom->greaterThan($period) ? $overdueFrom : $period;
        // Начисление прекращается со дня оплаты — последний день просрочки предыдущий.
        $until = $settled === null ? $monthEnd : $settled->subDay();
        $until = $until->greaterThan($monthEnd) ? $monthEnd : $until;

        if ($from->greaterThan($until)) {
            return null;
        }

        $payments = $this->payments($invoice);
        $total = (float) $invoice->total_amount;

        $days = 0;
        $excludedDays = 0;
        $exclusionReason = null;
        $integral = 0.0;
        $balance = $total;

        for ($day = $from; $day->lte($until); $day = $day->addDay()) {
            $paid = 0.0;
            foreach ($payments as $payment) {
                if ($payment['date']->lte($day)) {
                    $paid += $payment['amount'];
                }
            }

            $balance = $total - $paid;

            if ($balance <= 0) {
                break;   // долг закрыт платежами — дальше начислять нечего
            }

            $reason = $this->exclusionReason($exclusions, (int) ($invoice->shipment_id ?? 0), $day);

            if ($reason !== null) {
                $excludedDays++;
                $exclusionReason ??= $reason;

                continue;
            }

            $days++;
            $integral += $balance;
        }

        return [
            'days' => $days,
            'integral' => Money::round($integral),
            'balance_end' => Money::round(max(0.0, $balance)),
            'excluded_days' => $excludedDays,
            'exclusion_reason' => $exclusionReason,
        ];
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
     * Платежи по накладной из улик моста.
     *
     * @return list<array{date: CarbonImmutable, amount: float}>
     */
    private function payments(PayrollInvoiceSettlement $invoice): array
    {
        $rows = [];

        foreach ((array) ($invoice->payments ?? []) as $payment) {
            if (! isset($payment['date'])) {
                continue;
            }

            $rows[] = [
                'date' => CarbonImmutable::parse((string) $payment['date'])->startOfDay(),
                'amount' => (float) ($payment['amount'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Действующие в периоде исключения задолженности.
     *
     * @param  list<int>  $partnerIds
     * @return list<array{shipment_id: int|null, from: CarbonImmutable, until: CarbonImmutable|null, reason: string}>
     */
    private function exclusions(array $partnerIds, CarbonImmutable $period, CarbonImmutable $monthEnd): array
    {
        return MotivationDebtExclusion::query()
            ->whereIn('user_id', $partnerIds)
            ->whereDate('excluded_from', '<=', $monthEnd)
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
