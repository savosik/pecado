<?php

namespace App\Services\Motivation;

use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Очередь разметки накладных с ценой в рублях (карточка mot-37; форма B8).
 *
 * Экран действующей системы переиспользуется; добавлена одна колонка — сколько
 * накладная добавляет к К1 работника за месяц, чтобы очередь разбиралась по
 * значимости, а не по порядку. Цена считается тем же интегратором, что расчёт.
 */
class InvoiceReviewQueueService
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly PartnerAttributionResolver $attribution,
        private readonly ParameterOrderService $orders,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $month, ?int $managerId, int $page): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $values = $this->orders->effective($period)['values'];
        $rate = (float) ($values['rate_k1_per_day'] ?? 0);

        $managers = PersonalManager::query()->active()->where('payroll_enabled', true)
            ->when($managerId !== null, fn ($q) => $q->whereKey($managerId))
            ->orderBy('name')->get(['id', 'name']);

        $managerByPartner = [];
        $names = [];
        foreach ($managers as $manager) {
            $partners = $this->attribution->partnersOf((int) $manager->getKey(), $period->endOfMonth());
            foreach ($partners as $id => $name) {
                $managerByPartner[$id] = (string) $manager->name;
                $names[$id] = $name;
            }
        }

        $partnerIds = array_keys($managerByPartner);
        $query = PayrollInvoiceSettlement::query()
            ->with('manualBy:id,name')
            ->where('needs_review', true)
            ->when($partnerIds !== [], fn ($q) => $q->whereIn('user_id', $partnerIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $period->endOfMonth());

        $total = (clone $query)->count();
        $amount = (float) (clone $query)->sum('total_amount');

        $rows = $query->get(['id', 'shipment_id', 'user_id', 'erp_number', 'total_amount', 'shipped_on', 'due_on', 'settled_on', 'settled_source', 'payments', 'manual_settled_on', 'manual_comment', 'manual_by_user_id', 'matched_settled_on', 'matched_paid_amount', 'needs_review'])
            ->map(fn (PayrollInvoiceSettlement $row): array => [
                'id' => (int) $row->getKey(),
                'shipment_id' => (int) $row->shipment_id,
                'erp_number' => $row->erp_number,
                'partner_id' => (int) $row->user_id,
                'partner_name' => $names[(int) $row->user_id] ?? '',
                'manager_name' => $managerByPartner[(int) $row->user_id] ?? '',
                'amount' => (float) $row->total_amount,
                'shipped_on' => $row->shipped_on?->toDateString(),
                'due_on' => $row->due_on?->toDateString(),
                'settled_on' => $row->settled_on?->toDateString(),
                'settled_source' => $row->settled_source,
                'matched_settled_on' => $row->matched_settled_on?->toDateString(),
                'manual_settled_on' => $row->manual_settled_on?->toDateString(),
                'manual_comment' => $row->manual_comment,
                'manual_by' => $row->manualBy === null ? null : (string) $row->manualBy->name,
                'payments' => $row->payments ?? [],
                // 1С считает накладную оплаченной, а какого числа — мост не нашёл: эта сумма
                // в вычет не идёт, пока руководитель не проставит дату (незнание — в пользу работника).
                'undated' => Money::round(max(0.0, (float) $row->total_amount - (float) $row->matched_paid_amount)),
            ])
            ->sortByDesc(fn (array $r): array => [$r['undated'], $r['amount']])
            ->values();

        $lastPage = max(1, (int) ceil($rows->count() / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return [
            'month' => $period->toDateString(),
            'month_label' => MonthLabel::ru($period),
            'rate_per_day' => $rate,
            'managers' => $managers->map(fn (PersonalManager $m): array => ['id' => (int) $m->getKey(), 'name' => (string) $m->name])->all(),
            'summary' => [
                'total' => $total,
                'amount' => Money::round($amount),
                'undated' => Money::round($rows->sum('undated')),
            ],
            'rows' => [
                'data' => $rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()->all(),
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $rows->count(),
                'last_page' => $lastPage,
            ],
        ];
    }
}
