<?php

namespace App\Services\Notifications\Pulse;

use App\Models\ContractorBalance;
use App\Models\NotificationSignal;
use App\Models\PrintedDocument;
use App\Models\ShipmentPaymentSchedule;
use App\Notifications\Pulse\Support\PulseSignal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Плановый обход финансового состояния.
 *
 * Финансовые события — плановые, а не реактивные: 1С шлёт снимок баланса,
 * а не событие «наступила просрочка», причём `balance.updated` по одному
 * партнёру приходит часто и не по порядку. Ставить сигнал на входящее
 * сообщение значило бы слать письмо на каждый пересчёт.
 *
 * Просрочка — состояние, поэтому события порождаются **на переходах**:
 * возникла, выросла, погашена. Иначе правило «просрочка больше 30 дней»
 * писало бы бухгалтеру каждый день, пока он не заплатит.
 *
 * Предыдущее состояние берётся из последнего сигнала по контрагенту —
 * отдельной таблицы для снимка заводить не нужно.
 */
class FinanceScanner
{
    /** Ступени, пересечение которых считается ухудшением. */
    private const STEPS = [30, 60, 90];

    public function __construct(private readonly NotificationPulse $pulse) {}

    /**
     * @return array{due_soon: int, started: int, grew: int, cleared: int}
     */
    public function scan(int $horizonDays = 3, bool $dryRun = false): array
    {
        $today = CarbonImmutable::today();

        return [
            'due_soon' => $this->scanDueSoon($today, $horizonDays, $dryRun),
            ...$this->scanOverdue($today, $dryRun),
        ];
    }

    /**
     * Строки графика оплат, срок по которым подходит.
     */
    private function scanDueSoon(CarbonImmutable $today, int $horizonDays, bool $dryRun): int
    {
        $rows = ShipmentPaymentSchedule::query()
            ->with(['shipment.user', 'shipment.company'])
            ->whereBetween('due_date', [$today->toDateString(), $today->addDays($horizonDays)->toDateString()])
            ->get()
            ->filter(fn (ShipmentPaymentSchedule $row) => $this->unpaid($row) > 0.009);

        $count = 0;

        foreach ($rows as $row) {
            $shipment = $row->shipment;

            if ($shipment === null || $shipment->user_id === null) {
                continue;
            }

            $dueDate = Carbon::parse($row->due_date);

            $this->pulse->signal(new PulseSignal(
                eventKey: 'finance.payment_due_soon',
                clientUserId: $shipment->user_id,
                companyId: $shipment->company_id,
                subject: $shipment,
                data: [
                    'days_left' => $today->diffInDays($dueDate, false),
                    'amount' => round($this->unpaid($row), 2),
                    'due_date' => $dueDate->toDateString(),
                    'shipment_number' => $shipment->erp_number ?? $shipment->number ?? null,
                    'organization_id' => $shipment->organization_id,
                    'has_invoice_document' => $this->hasInvoice($shipment->id),
                ],
                view: [
                    'title' => 'Напоминание об оплате',
                    'body' => sprintf(
                        'По реализации %s срок оплаты — %s. К оплате: %s ₽.',
                        $shipment->erp_number ?? $shipment->number ?? '—',
                        $dueDate->format('d.m.Y'),
                        number_format($this->unpaid($row), 2, ',', ' '),
                    ),
                ],
            ), dryRun: $dryRun);

            $count++;
        }

        return $count;
    }

    /**
     * Просрочка по контрагентам: сравнение с предыдущим состоянием.
     *
     * @return array{started: int, grew: int, cleared: int}
     */
    private function scanOverdue(CarbonImmutable $today, bool $dryRun): array
    {
        $result = ['started' => 0, 'grew' => 0, 'cleared' => 0];

        $balances = ContractorBalance::query()
            ->with('overdueDetails')
            ->where(function ($q) {
                $q->where('overdue_debt', '>', 0)
                    ->orWhereIn('company_id', $this->companiesWithPreviousOverdue());
            })
            ->get();

        foreach ($balances as $balance) {
            if ($balance->user_id === null) {
                continue;
            }

            $current = $this->currentState($balance, $today);
            $previous = $this->previousState($balance->company_id);

            $transition = $this->detectTransition($current, $previous);

            if ($transition === null) {
                continue;
            }

            [$eventKey, $data, $view] = $transition;

            $this->pulse->signal(new PulseSignal(
                eventKey: $eventKey,
                clientUserId: $balance->user_id,
                companyId: $balance->company_id,
                data: $data,
                view: $view,
            ), dryRun: $dryRun);

            $result[str_replace('finance.overdue_', '', $eventKey)]++;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentState(ContractorBalance $balance, CarbonImmutable $today): array
    {
        $details = $balance->overdueDetails;
        $oldest = $details->min('due_date');

        return [
            'overdue_amount' => round((float) $balance->overdue_debt, 2),
            'total_debt' => round((float) $balance->current_balance, 2),
            // Порядок аргументов значим: diffInDays возвращает знаковую разницу,
            // и today->diff(прошлое) дал бы отрицательные дни просрочки.
            'days_overdue' => $oldest ? (int) Carbon::parse($oldest)->diffInDays($today) : 0,
            'oldest_due_date' => $oldest ? Carbon::parse($oldest)->toDateString() : null,
            'positions_count' => $details->count(),
        ];
    }

    /**
     * Предыдущее состояние — из последнего финансового сигнала по контрагенту.
     *
     * @return array<string, mixed>|null
     */
    private function previousState(?int $companyId): ?array
    {
        if ($companyId === null) {
            return null;
        }

        $signal = NotificationSignal::query()
            ->where('company_id', $companyId)
            ->whereIn('event_key', [
                'finance.overdue_started',
                'finance.overdue_grew',
                'finance.overdue_cleared',
            ])
            ->latest('id')
            ->first();

        if ($signal === null) {
            return null;
        }

        $data = (array) $signal->data;

        // Погашение обнуляет состояние: следующая просрочка — снова «возникла»
        if ($signal->event_key === 'finance.overdue_cleared') {
            return ['overdue_amount' => 0.0, 'days_overdue' => 0];
        }

        return [
            'overdue_amount' => (float) ($data['overdue_amount'] ?? 0),
            'days_overdue' => (int) ($data['days_overdue'] ?? 0),
        ];
    }

    /**
     * Какой переход произошёл — и произошёл ли вообще.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}|null
     */
    private function detectTransition(array $current, ?array $previous): ?array
    {
        $amount = (float) $current['overdue_amount'];
        $days = (int) $current['days_overdue'];

        $wasAmount = (float) ($previous['overdue_amount'] ?? 0);
        $wasDays = (int) ($previous['days_overdue'] ?? 0);

        if ($amount <= 0.009) {
            if ($wasAmount > 0.009) {
                return ['finance.overdue_cleared', [
                    'was_days_overdue' => $wasDays,
                    'was_amount' => $wasAmount,
                ], [
                    'title' => 'Задолженность погашена',
                    'body' => 'Просроченная задолженность закрыта полностью. Спасибо!',
                ]];
            }

            return null;
        }

        if ($wasAmount <= 0.009) {
            return ['finance.overdue_started', $current, [
                'title' => 'Просроченная задолженность',
                'body' => $this->overdueBody($current),
            ]];
        }

        $crossedStep = $this->crossedStep($days, $wasDays);
        $amountGrew = $amount > $wasAmount + 0.009;

        // Ничего не изменилось — молчим. Именно это отличает состояние
        // от события: просрочка есть каждый день, а новость — не каждый.
        if (! $amountGrew && $crossedStep === null) {
            return null;
        }

        return ['finance.overdue_grew', $current + [
            'previous_days_overdue' => $wasDays,
            'previous_amount' => $wasAmount,
            'crossed_step' => $crossedStep === null ? null : (string) $crossedStep,
        ], [
            'title' => 'Просроченная задолженность выросла',
            'body' => $this->overdueBody($current),
        ]];
    }

    private function crossedStep(int $days, int $wasDays): ?int
    {
        foreach (self::STEPS as $step) {
            if ($days >= $step && $wasDays < $step) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function overdueBody(array $state): string
    {
        return sprintf(
            'Просроченная задолженность: %s ₽ по %d документам, самый ранний срок — %s.',
            number_format((float) $state['overdue_amount'], 2, ',', ' '),
            (int) $state['positions_count'],
            $state['oldest_due_date'] ? Carbon::parse($state['oldest_due_date'])->format('d.m.Y') : '—',
        );
    }

    private function unpaid(ShipmentPaymentSchedule $row): float
    {
        // 1С гасит долг ещё и авансами по заказам, поэтому остаток графика —
        // это сумма за вычетом оплаченного И предоплаченного.
        return (float) $row->amount - (float) $row->paid_amount - (float) $row->prepaid_amount;
    }

    private function hasInvoice(int $shipmentId): bool
    {
        return PrintedDocument::query()
            ->where('shipment_id', $shipmentId)
            ->where('file_status', PrintedDocument::FILE_STORED)
            ->where('type', \App\Enums\PrintedDocumentType::INVOICE)
            ->exists();
    }

    /**
     * Контрагенты, у которых просрочка была в прошлый раз, — чтобы заметить
     * её погашение, когда в балансе уже ноль.
     *
     * @return array<int, int>
     */
    private function companiesWithPreviousOverdue(): array
    {
        return NotificationSignal::query()
            ->whereIn('event_key', ['finance.overdue_started', 'finance.overdue_grew'])
            ->whereNotNull('company_id')
            ->where('created_at', '>=', now()->subDays(90))
            ->distinct()
            ->pluck('company_id')
            ->all();
    }
}
