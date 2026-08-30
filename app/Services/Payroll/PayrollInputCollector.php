<?php

namespace App\Services\Payroll;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Crm\ClientPlanFactService;
use App\Services\Crm\PlanProgressService;
use App\Services\Crm\PlanScope;
use App\Services\Payroll\Dto\AdjustmentInput;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Сбор входов расчёта за месяц: всё, что калькулятору нужно знать о мире.
 *
 * Выручка — только через {@see ShipmentAnalyticsService}: та же цифра, что на
 * `/crm/plans` и `/crm/analytics`; свой SUM по отгрузкам был бы вторым движком.
 * Кэш планов здесь не используется намеренно: черновик зарплаты пересчитывается
 * по событию, и пятиминутная свежесть агрегата превратила бы «на эту минуту»
 * в «как повезёт».
 *
 * Клиенты менеджера — по текущему `personal_manager_id` (решение эпика:
 * атрибуция по факту, журнала смен менеджера нет).
 */
class PayrollInputCollector
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly PlanProgressService $progress,
        private readonly ClientPlanFactService $clientPlanFact,
        private readonly WorkingCalendar $calendar,
    ) {}

    /**
     * @param  array<string, mixed>  $newClientsParams  параметры компонента «Новые клиенты»
     *                                                  (сроки повтора и паузы); пусто — умолчания
     */
    public function collect(int $managerId, CarbonInterface $month, array $newClientsParams = []): PayrollInputs
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $managerName = (string) (PersonalManager::query()->whereKey($managerId)->value('name') ?? '');

        $clients = User::query()
            ->clients()
            ->where('personal_manager_id', $managerId)
            ->get(['id', 'name', 'erp_name']);

        /** @var array<int, string> $names */
        $names = [];
        foreach ($clients as $client) {
            $names[(int) $client->getKey()] = (string) $client->display_name;
        }
        $clientIds = array_keys($names);

        $scope = PlanScope::manager($managerId, $clientIds, $managerName);

        return new PayrollInputs(
            managerId: $managerId,
            month: $period->toDateString(),
            plan: $this->progress->planAmount($period, $scope),
            revenue: $this->revenue($clientIds, $period),
            plannedClients: $this->plannedClients($clientIds, $names, $period),
            invoices: $this->settledInvoices($clientIds, $names, $period),
            atRiskInvoices: $this->atRiskInvoices($clientIds, $names, $period),
            extraItems: $this->adjustments($managerId, $period, PayrollManualAdjustment::COMPONENT_EXTRA_INCOME),
            corrections: $this->adjustments($managerId, $period, PayrollManualAdjustment::COMPONENT_MANUAL_CORRECTION),
            newClients: $this->newClients($clientIds, $names, $period, $newClientsParams),
            workingDays: $this->calendar->monthDays($period),
            collectedAt: now()->toIso8601String(),
        );
    }

    /**
     * Лента отгрузок месяца для экрана: документы, а не суммы — итог считает движок.
     *
     * @return array{rows: list<array<string, mixed>>, total_count: int, truncated: bool}
     */
    public function shipmentsTimeline(int $managerId, CarbonInterface $month, int $limit = 80): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();

        $clients = User::query()
            ->clients()
            ->where('personal_manager_id', $managerId)
            ->get(['id', 'name', 'erp_name']);

        if ($clients->isEmpty()) {
            return ['rows' => [], 'total_count' => 0, 'truncated' => false];
        }

        $names = [];
        foreach ($clients as $client) {
            $names[(int) $client->getKey()] = (string) $client->display_name;
        }

        $planned = CrmSalesPlan::query()
            ->forPeriod($period)
            ->where('target_type', PlanTarget::CLIENT->value)
            ->whereIn('target_id', array_keys($names))
            ->pluck('target_id')
            ->map('intval')
            ->all();

        $query = Shipment::query()
            ->withoutInternalOrganizations()
            ->whereIn('user_id', array_keys($names))
            ->whereBetween('erp_created_at', [$period->startOfDay(), $period->endOfMonth()->endOfDay()]);

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('erp_created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'erp_number', 'user_id', 'erp_created_at', 'total_amount', 'payment_status'])
            ->map(fn (Shipment $shipment): array => [
                'id' => (int) $shipment->getKey(),
                'number' => $shipment->erp_number,
                'date' => $shipment->erp_created_at?->toDateString(),
                'partner_id' => $shipment->user_id === null ? null : (int) $shipment->user_id,
                'partner_name' => $names[(int) $shipment->user_id] ?? '',
                'amount' => (float) $shipment->total_amount,
                'payment_status' => $shipment->payment_status,
                'is_planned' => in_array((int) $shipment->user_id, $planned, true),
            ])
            ->all();

        return ['rows' => $rows, 'total_count' => $total, 'truncated' => $total > count($rows)];
    }

    /**
     * Новые, повторившие и вернувшиеся партнёры месяца.
     *
     * Новый — первая в истории отгрузка в этом месяце (сумма первого дня; порог
     * отсекает тестовые заказы). Повтор — первая отгрузка была раньше, вторая
     * в этом месяце и не позже отведённого срока. Вернувшийся — пауза перед
     * отгрузкой месяца дольше порога спящего.
     *
     * Четыре агрегата одним GROUP BY по индексу `(user_id, erp_created_at)`,
     * а не выборка всех дат: у менеджера сотни партнёров и десятки тысяч
     * отгрузок за историю, и `DISTINCT ... ORDER BY DATE(...)` по ним валил
     * запрос в MySQL «Out of sort memory» (поймано на dev 30.08.2026).
     * «Вторая отгрузка в истории» выводится без выборки: до месяца была ровно
     * одна дата отгрузки — значит первая дата месяца и есть вторая в истории.
     *
     * @param  list<int>  $clientIds
     * @param  array<int, string>  $names
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function newClients(array $clientIds, array $names, CarbonImmutable $period, array $params): array
    {
        if ($clientIds === []) {
            return [];
        }

        $repeatWithin = max(1, (int) ($params['repeat_within_days'] ?? 60));
        $returnedAfter = max(1, (int) ($params['returned_after_days'] ?? config('crm.lifecycle.sleeping_after_days', 90)));
        $startAt = $period->startOfDay()->toDateTimeString();
        $endAt = $period->endOfMonth()->endOfDay()->toDateTimeString();

        $rows = Shipment::query()
            ->withoutInternalOrganizations()
            ->whereIn('user_id', $clientIds)
            ->whereNotNull('erp_created_at')
            ->where('erp_created_at', '<=', $endAt)
            ->where('total_amount', '>', 0)
            ->groupBy('user_id')
            ->selectRaw('user_id')
            ->selectRaw('MIN(DATE(erp_created_at)) as first_day')
            ->selectRaw('MIN(CASE WHEN erp_created_at >= ? THEN DATE(erp_created_at) END) as first_in_month', [$startAt])
            ->selectRaw('MAX(CASE WHEN erp_created_at < ? THEN DATE(erp_created_at) END) as last_before', [$startAt])
            ->selectRaw('COUNT(DISTINCT CASE WHEN erp_created_at < ? THEN DATE(erp_created_at) END) as days_before', [$startAt])
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $userId = (int) $row->getAttribute('user_id');
            $first = (string) $row->getAttribute('first_day');
            $inMonth = $row->getAttribute('first_in_month');

            if ($inMonth === null) {
                continue;   // в этом месяце партнёр не отгружался
            }

            $inMonth = (string) $inMonth;
            $name = $names[$userId] ?? ('#'.$userId);

            if ((int) $row->getAttribute('days_before') === 0) {
                $result[] = [
                    'id' => $userId,
                    'name' => $name,
                    'kind' => 'new',
                    'stage' => 'first',
                    'first_shipment_on' => $first,
                    'first_amount' => $this->dayTotal($userId, $first),
                    'shipment_on' => $first,
                ];

                continue;
            }

            $lastBefore = (string) $row->getAttribute('last_before');

            // Вторая отгрузка в истории пришлась на этот месяц.
            if ((int) $row->getAttribute('days_before') === 1) {
                $gap = (int) CarbonImmutable::parse($first)->diffInDays(CarbonImmutable::parse($inMonth));

                if ($gap <= $repeatWithin) {
                    $result[] = [
                        'id' => $userId,
                        'name' => $name,
                        'kind' => 'new',
                        'stage' => 'repeat',
                        'first_shipment_on' => $first,
                        'first_amount' => $this->dayTotal($userId, $first),
                        'shipment_on' => $inMonth,
                        'repeat_after_days' => $gap,
                    ];

                    continue;
                }
            }

            $gap = (int) CarbonImmutable::parse($lastBefore)->diffInDays(CarbonImmutable::parse($inMonth));

            if ($gap > $returnedAfter) {
                $result[] = [
                    'id' => $userId,
                    'name' => $name,
                    'kind' => 'returned',
                    'stage' => 'first',
                    'first_shipment_on' => $first,
                    'first_amount' => $this->dayTotal($userId, $inMonth),
                    'shipment_on' => $inMonth,
                    'gap_days' => $gap,
                    'last_before' => $lastBefore,
                ];
            }
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['shipment_on'], $b['shipment_on']));

        return $result;
    }

    /**
     * Сумма отгрузок партнёра за день — атрибут документов, не аналитика.
     *
     * Диапазоном, а не `whereDate`: функция над колонкой отключила бы индекс
     * `(user_id, erp_created_at)`.
     */
    private function dayTotal(int $userId, string $day): float
    {
        $from = CarbonImmutable::parse($day)->startOfDay();

        return round((float) Shipment::query()
            ->withoutInternalOrganizations()
            ->where('user_id', $userId)
            ->whereBetween('erp_created_at', [$from->toDateTimeString(), $from->endOfDay()->toDateTimeString()])
            ->sum('total_amount'), 2);
    }

    /**
     * Реализации партнёров за месяц в рублях по бизнес-дате 1С.
     *
     * @param  list<int>  $clientIds
     */
    private function revenue(array $clientIds, CarbonImmutable $period): float
    {
        if ($clientIds === []) {
            return 0.0;
        }

        $ctx = AnalyticsContext::forScope($clientIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return 0.0;
        }

        $metrics = $this->analytics->metrics($ctx, new AnalyticsFilters(
            dateFrom: $period->startOfDay(),
            dateTo: $period->endOfMonth()->endOfDay(),
        ));

        return round((float) $metrics['total_amount'], 2);
    }

    /**
     * Партнёры с планом на месяц и их факт — основа множителя по активным клиентам.
     *
     * @param  list<int>  $clientIds
     * @param  array<int, string>  $names
     * @return list<PlannedClientInput>
     */
    private function plannedClients(array $clientIds, array $names, CarbonImmutable $period): array
    {
        if ($clientIds === []) {
            return [];
        }

        $plannedIds = CrmSalesPlan::query()
            ->forPeriod($period)
            ->where('target_type', PlanTarget::CLIENT->value)
            ->whereIn('target_id', $clientIds)
            ->pluck('target_id')
            ->map('intval')
            ->all();

        if ($plannedIds === []) {
            return [];
        }

        $rows = $this->clientPlanFact->forClients($plannedIds, $period);

        $result = [];
        foreach ($plannedIds as $id) {
            $result[] = new PlannedClientInput(
                id: $id,
                name: $names[$id] ?? ('#'.$id),
                plan: isset($rows[$id]) ? $rows[$id]['plan'] : null,
                fact: isset($rows[$id]) ? (float) $rows[$id]['fact'] : 0.0,
            );
        }

        usort($result, fn (PlannedClientInput $a, PlannedClientInput $b): int => $b->fact <=> $a->fact ?: strcmp($a->name, $b->name));

        return $result;
    }

    /**
     * Накладные партнёров, закрытые в месяце с известной задержкой.
     *
     * @param  list<int>  $clientIds
     * @param  array<int, string>  $names
     * @return list<InvoiceInput>
     */
    private function settledInvoices(array $clientIds, array $names, CarbonImmutable $period): array
    {
        if ($clientIds === []) {
            return [];
        }

        return PayrollInvoiceSettlement::query()
            ->whereIn('user_id', $clientIds)
            ->settledIn($period)
            ->orderBy('settled_on')
            ->orderBy('id')
            ->get()
            ->map(fn (PayrollInvoiceSettlement $row): InvoiceInput => $this->invoice($row, $names))
            ->all();
    }

    /**
     * Неоплаченные накладные со сроком не позже конца месяца — риск штрафа и материал советов.
     *
     * @param  list<int>  $clientIds
     * @param  array<int, string>  $names
     * @return list<InvoiceInput>
     */
    private function atRiskInvoices(array $clientIds, array $names, CarbonImmutable $period): array
    {
        if ($clientIds === []) {
            return [];
        }

        return PayrollInvoiceSettlement::query()
            ->whereIn('user_id', $clientIds)
            ->whereNull('settled_on')
            ->whereIn('payment_status', [Shipment::PAYMENT_UNPAID, Shipment::PAYMENT_PARTIAL])
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $period->endOfMonth()->toDateString())
            ->where('total_amount', '>', 0.01)
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->map(fn (PayrollInvoiceSettlement $row): InvoiceInput => $this->invoice($row, $names))
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     */
    private function invoice(PayrollInvoiceSettlement $row, array $names): InvoiceInput
    {
        return new InvoiceInput(
            shipmentId: (int) $row->shipment_id,
            erpNumber: $row->erp_number,
            partnerId: $row->user_id === null ? null : (int) $row->user_id,
            partnerName: $row->user_id === null ? '' : ($names[(int) $row->user_id] ?? ''),
            amount: (float) $row->total_amount,
            shippedOn: $row->shipped_on?->toDateString(),
            dueOn: $row->due_on?->toDateString(),
            settledOn: $row->settled_on?->toDateString(),
            delayWorkingDays: $row->delay_working_days,
            delayCalendarDays: $row->delay_calendar_days,
            source: $row->settled_source,
            paymentStatus: $row->payment_status,
        );
    }

    /**
     * @return list<AdjustmentInput>
     */
    private function adjustments(int $managerId, CarbonImmutable $period, string $componentKey): array
    {
        return PayrollManualAdjustment::query()
            ->forManager($managerId)
            ->forPeriod($period)
            ->forComponent($componentKey)
            ->orderBy('id')
            ->get()
            ->map(fn (PayrollManualAdjustment $row): AdjustmentInput => new AdjustmentInput(
                id: (int) $row->getKey(),
                label: (string) $row->label,
                qty: (float) $row->qty,
                price: (float) $row->price,
                amount: (float) $row->amount,
                comment: $row->comment,
            ))
            ->all();
    }
}
