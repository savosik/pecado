<?php

namespace App\Services\Motivation;

use App\Events\Payroll\PayrollInputsChanged;
use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Исключения задолженности из базы К1 (карточка mot-37; форма B9).
 *
 * Решение заказчика от 08.09.2026: старая задолженность расчищается — безнадёжная
 * списывается, по остальной ведётся претензионная работа, и то и другое прекращает
 * начисление вычета. Исключение действует с даты и закрывается датой, а не
 * удалением: история обязана сохраняться. Утверждённые расчёты не меняются —
 * они читают снимок; черновики пересчитываются событием.
 *
 * Кандидаты — долги старше заданного срока с ценой «в месяц»: остаток × ставка
 * К1 × календарные дни текущего месяца. Именно эта колонка делает разбор списка
 * осмысленным: расчищать сначала то, что дороже.
 */
class DebtExclusionService
{
    public const REASONS = [
        MotivationDebtExclusion::REASON_WRITTEN_OFF => 'списан безнадёжным',
        MotivationDebtExclusion::REASON_LEGAL => 'передан в претензионную работу',
        MotivationDebtExclusion::REASON_DISPUTED => 'оспаривается партнёром',
        MotivationDebtExclusion::REASON_OTHER => 'иное',
    ];

    public function __construct(
        private readonly OverdueDebtIntegrator $integrator,
        private readonly PartnerAttributionResolver $attribution,
        private readonly ParameterOrderService $orders,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(int $olderThanDays, ?int $managerId = null): array
    {
        $today = CarbonImmutable::today();
        $month = $today->startOfMonth();
        $values = $this->orders->effective($month)['values'];
        $rate = (float) ($values['rate_k1_per_day'] ?? 0);
        $grace = (int) ($values['grace_working_days'] ?? 5);

        $managers = PersonalManager::query()->active()->where('payroll_enabled', true)
            ->when($managerId !== null, fn ($q) => $q->whereKey($managerId))
            ->orderBy('name')->get(['id', 'name']);

        $candidates = [];
        $partnersSeen = [];
        $totalAmount = 0.0;
        $totalMonthly = 0.0;

        foreach ($managers as $manager) {
            $names = $this->attribution->partnersOf((int) $manager->getKey(), $today);
            if ($names === []) {
                continue;
            }

            $result = $this->integrator->forMonth(array_keys($names), $month, $names, $grace);

            foreach ($result['rows'] as $row) {
                $graceEnds = CarbonImmutable::parse((string) $row['grace_ends_on']);
                $overdueDays = $graceEnds->lessThan($today) ? (int) $graceEnds->diffInDays($today) : 0;
                $balance = (float) $row['balance_end'];

                if ($overdueDays < $olderThanDays || $balance <= 0 || $row['settled_on'] !== null) {
                    continue;
                }

                $monthly = Money::round($balance * $rate * $today->daysInMonth);
                $partnersSeen[(int) $row['partner_id']] = true;
                $totalAmount += $balance;
                $totalMonthly += $monthly;

                $candidates[] = [
                    'invoice_id' => (int) $row['invoice_id'],
                    'shipment_id' => (int) $row['shipment_id'],
                    'number' => (string) $row['number'],
                    'partner_id' => (int) $row['partner_id'],
                    'partner_name' => (string) $row['partner_name'],
                    'manager' => ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name],
                    'amount' => (float) $row['amount'],
                    'balance' => Money::round($balance),
                    'shipped_on' => $row['shipped_on'],
                    'due_on' => $row['due_on'],
                    'overdue_days' => $overdueDays,
                    'monthly_cost' => $monthly,
                    'needs_review' => (bool) ($row['needs_review'] ?? false),
                ];
            }
        }

        usort($candidates, fn (array $a, array $b): int => $b['monthly_cost'] <=> $a['monthly_cost']);

        return [
            'older_than_days' => $olderThanDays,
            'rate_per_day' => $rate,
            'days_in_month' => $today->daysInMonth,
            'managers' => $managers->map(fn (PersonalManager $m): array => ['id' => (int) $m->getKey(), 'name' => (string) $m->name])->all(),
            'summary' => [
                'invoices' => count($candidates),
                'partners' => count($partnersSeen),
                'amount' => Money::round($totalAmount),
                'monthly_cost' => Money::round($totalMonthly),
            ],
            'candidates' => $candidates,
            'exclusions' => $this->exclusions(),
            'reasons' => self::REASONS,
        ];
    }

    /**
     * Исключить долг: по документу или по партнёру целиком.
     *
     * @param  array<string, mixed>  $data  reason, excluded_from, document_ref, shipment_id?, user_id?, amount?, comment?
     *
     * @throws \InvalidArgumentException
     */
    public function create(array $data, User $actor): MotivationDebtExclusion
    {
        $reason = (string) ($data['reason'] ?? '');
        if (! array_key_exists($reason, self::REASONS)) {
            throw new \InvalidArgumentException('Укажите основание исключения.');
        }

        $from = CarbonImmutable::parse((string) $data['excluded_from'])->startOfDay();
        $until = empty($data['excluded_until']) ? null : CarbonImmutable::parse((string) $data['excluded_until'])->startOfDay();
        if ($until !== null && $until->lessThan($from)) {
            throw new \InvalidArgumentException('Дата окончания раньше даты начала.');
        }

        $shipmentId = empty($data['shipment_id']) ? null : (int) $data['shipment_id'];
        $userId = empty($data['user_id']) ? null : (int) $data['user_id'];

        if ($shipmentId !== null) {
            $invoice = PayrollInvoiceSettlement::query()->where('shipment_id', $shipmentId)->first(['user_id']);
            if ($invoice === null) {
                throw new \InvalidArgumentException('Накладная не найдена в мосте расчёта.');
            }
            $userId = (int) $invoice->user_id;
        }

        if ($userId === null) {
            throw new \InvalidArgumentException('Укажите накладную или партнёра.');
        }

        $duplicate = MotivationDebtExclusion::query()
            ->where('user_id', $userId)
            ->where(fn ($q) => $shipmentId === null ? $q->whereNull('shipment_id') : $q->where('shipment_id', $shipmentId))
            ->where(fn ($q) => $q->whereNull('excluded_until')->orWhereDate('excluded_until', '>=', $from))
            ->exists();
        if ($duplicate) {
            throw new \InvalidArgumentException('По этому долгу уже действует исключение — закройте его датой, если нужно изменить.');
        }

        $exclusion = MotivationDebtExclusion::query()->create([
            'shipment_id' => $shipmentId,
            'user_id' => $userId,
            'reason' => $reason,
            'excluded_from' => $from->toDateString(),
            'excluded_until' => $until?->toDateString(),
            'amount' => isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null,
            'document_ref' => (string) $data['document_ref'],
            'comment' => $data['comment'] ?? null,
            'author_id' => $actor->getKey(),
        ]);

        $this->notify($userId, $from);

        return $exclusion;
    }

    /**
     * Вернуть долг в базу начисления: закрыть исключение датой.
     *
     * @throws \InvalidArgumentException
     */
    public function close(MotivationDebtExclusion $exclusion, CarbonInterface $until): MotivationDebtExclusion
    {
        $until = CarbonImmutable::instance($until)->startOfDay();

        if ($until->lessThan($exclusion->excluded_from)) {
            throw new \InvalidArgumentException('Дата окончания раньше даты начала исключения.');
        }

        $exclusion->forceFill(['excluded_until' => $until->toDateString()])->save();
        $this->notify((int) $exclusion->user_id, $until);

        return $exclusion;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exclusions(): array
    {
        $today = CarbonImmutable::today();

        return MotivationDebtExclusion::query()
            ->with(['partner:id,name,erp_name', 'shipment:id,erp_number', 'author:id,name'])
            ->orderByDesc('excluded_from')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (MotivationDebtExclusion $row) use ($today): array {
                $active = $row->excluded_from->lte($today) && ($row->excluded_until === null || $row->excluded_until->gte($today));

                return [
                    'id' => (int) $row->getKey(),
                    'partner_id' => (int) $row->user_id,
                    'partner_name' => $row->partner === null ? '' : (string) $row->partner->display_name,
                    'shipment_id' => $row->shipment_id === null ? null : (int) $row->shipment_id,
                    'number' => $row->shipment === null ? null : (string) $row->shipment->erp_number,
                    'reason' => $row->reason,
                    'reason_label' => self::REASONS[$row->reason] ?? $row->reason,
                    'excluded_from' => $row->excluded_from->toDateString(),
                    'excluded_until' => $row->excluded_until?->toDateString(),
                    'amount' => $row->amount === null ? null : (float) $row->amount,
                    'document_ref' => $row->document_ref,
                    'comment' => $row->comment,
                    'author' => $row->author === null ? null : (string) $row->author->name,
                    'active' => $active,
                    'status_label' => $active ? 'действует' : ($row->excluded_from->greaterThan($today) ? 'с '.$row->excluded_from->format('d.m.Y') : 'закрыто'),
                ];
            })
            ->all();
    }

    /**
     * Черновики затронутых месяцев — на пересчёт; утверждённые остаются как были.
     */
    private function notify(int $partnerId, CarbonImmutable $from): void
    {
        $managers = $this->attribution->managersOf([$partnerId], CarbonImmutable::today());
        $managerId = $managers[$partnerId] ?? null;

        if ($managerId === null) {
            return;
        }

        $months = [];
        for ($m = $from->startOfMonth(); $m->lte(CarbonImmutable::today()->startOfMonth()); $m = $m->addMonth()) {
            $months[] = $m->toDateString();
        }

        PayrollInputsChanged::dispatch([(int) $managerId], 'debt.exclusion', $months);
    }
}
