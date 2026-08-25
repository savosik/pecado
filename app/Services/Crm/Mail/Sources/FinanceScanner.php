<?php

namespace App\Services\Crm\Mail\Sources;

use App\Models\CrmEmail;
use App\Models\PrintedDocument;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Плановый обход финансового состояния.
 *
 * Финансовые поводы — плановые, а не реактивные: 1С шлёт снимок баланса,
 * а не событие «наступила просрочка», причём `balance.updated` по одному
 * партнёру приходит часто и не по порядку. Ставить повод на входящее
 * сообщение значило бы слать письмо на каждый пересчёт.
 *
 * Просрочка — состояние, поэтому события порождаются **на переходах**:
 * возникла, выросла, погашена. Иначе правило «просрочка больше 30 дней»
 * писало бы бухгалтеру каждый день, пока он не заплатит.
 *
 * Предыдущее состояние берётся из последнего письма по этому клиенту —
 * отдельной таблицы для снимка заводить не нужно.
 */
class FinanceScanner
{
    /** Ступени, пересечение которых считается ухудшением. */
    private const STEPS = [30, 60, 90];

    /** Поводы, из которых восстанавливается предыдущее состояние просрочки. */
    private const OVERDUE_EVENTS = [
        'finance.overdue_started',
        'finance.overdue_grew',
        'finance.overdue_cleared',
    ];

    public function __construct(private readonly MailStream $stream) {}

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
        // Планы заказов исключены: предоплата — не обязательство с сроком,
        // и напоминать по ней «срок оплаты подходит» нечего (та же граница,
        // что у просрочки в счётном ядре).
        $lines = SettlementEntry::query()
            ->outstanding()
            ->where('document_kind', '!=', 'order')
            ->whereBetween(DB::raw('DATE(date)'), [
                $today->toDateString(),
                $today->addDays($horizonDays)->toDateString(),
            ])
            ->get();

        $shipments = Shipment::query()
            ->whereIn('uuid', $lines->pluck('document_uuid')->filter()->unique())
            ->get()
            ->keyBy('uuid');

        $count = 0;

        foreach ($lines as $line) {
            $shipment = $shipments->get($line->document_uuid);

            if ($shipment === null || $shipment->user_id === null) {
                continue;
            }

            $dueDate = Carbon::parse($line->date);
            $unpaid = $line->unsettled_amount;

            $this->publish($dryRun, new Occasion(
                key: 'finance.payment_due_soon',
                clientUserId: $shipment->user_id,
                companyId: $shipment->company_id,
                subject: $shipment,
                data: [
                    'days_left' => $today->diffInDays($dueDate, false),
                    'amount' => round($unpaid, 2),
                    'due_date' => $dueDate->toDateString(),
                    'shipment_id' => $shipment->id,
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
                        number_format($unpaid, 2, ',', ' '),
                    ),
                ],
            ));

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

        // Состояние собирается из регистра: `overdue_details` от 1С удалены
        // из контракта в v16.0.0, а канал балансов сама 1С признала недостоверным.
        $states = $this->overdueStates($today);

        // Клиенты, которым в прошлый раз писали: без них не заметить погашение —
        // в регистре у них теперь пусто, и переход «cleared» некому породить.
        foreach ($this->clientsWithPreviousOverdue() as $clientId) {
            $states[$clientId] ??= $this->emptyState();
        }

        foreach ($states as $clientUserId => $current) {
            $previous = $this->previousState($clientUserId);

            $transition = $this->detectTransition($current, $previous);

            if ($transition === null) {
                continue;
            }

            [$eventKey, $data, $view] = $transition;

            $this->publish($dryRun, new Occasion(
                key: $eventKey,
                clientUserId: $clientUserId,
                companyId: $current['company_id'],
                data: $data,
                view: $view,
            ));

            $result[str_replace('finance.overdue_', '', $eventKey)]++;
        }

        return $result;
    }

    /**
     * Просрочка клиентов по регистру: сумма, возраст и контрагент-виновник.
     *
     * Ключ — id партнёра: письма адресуются ему, а не контрагенту. Юрлицо
     * подставляется то, у которого просрочка больше, — письмо уходит с ним
     * в реквизитах, и брать первое попавшееся было бы враньём.
     *
     * @return array<int, array<string, mixed>>
     */
    private function overdueStates(CarbonImmutable $today): array
    {
        $lines = SettlementEntry::query()
            ->overdue(Carbon::parse($today->toDateString()))
            // nature и document_kind обязательны в выборке: без них аксессоры
            // `unsettled_amount` и `is_overdue` считают строку не плановой
            // и молча возвращают ноль.
            ->get([
                'user_id', 'company_id', 'nature', 'document_kind',
                'amount', 'settled_amount', 'amount_rub', 'currency_code', 'date',
            ]);

        $byClient = [];

        foreach ($lines as $line) {
            $clientId = (int) $line->user_id;

            if ($clientId === 0) {
                continue;
            }

            $unpaid = $line->unsettled_amount;
            $date = $line->date?->toDateString();

            $state = $byClient[$clientId] ??= $this->emptyState() + ['by_company' => []];

            $state['overdue_amount'] += $unpaid;
            $state['positions_count']++;

            if ($date !== null && ($state['oldest_due_date'] === null || $date < $state['oldest_due_date'])) {
                $state['oldest_due_date'] = $date;
            }

            $companyId = $line->company_id === null ? 0 : (int) $line->company_id;
            $state['by_company'][$companyId] = ($state['by_company'][$companyId] ?? 0.0) + $unpaid;

            $byClient[$clientId] = $state;
        }

        // Сальдо клиента — сумма его фактических движений: то же число, что
        // в балансах CRM и кабинете.
        $debts = SettlementEntry::query()
            ->facts()
            ->whereIn('user_id', array_keys($byClient))
            ->selectRaw('user_id, SUM(COALESCE(amount_rub, amount)) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        foreach ($byClient as $clientId => $state) {
            arsort($state['by_company']);
            $topCompany = (int) array_key_first($state['by_company']);

            $byClient[$clientId] = [
                'overdue_amount' => round($state['overdue_amount'], 2),
                'total_debt' => round((float) ($debts[$clientId] ?? 0), 2),
                // Порядок аргументов значим: diffInDays возвращает знаковую
                // разницу, и today->diff(прошлое) дал бы отрицательные дни.
                'days_overdue' => $state['oldest_due_date']
                    ? (int) Carbon::parse($state['oldest_due_date'])->diffInDays($today)
                    : 0,
                'oldest_due_date' => $state['oldest_due_date'],
                'positions_count' => $state['positions_count'],
                'company_id' => $topCompany > 0 ? $topCompany : null,
            ];
        }

        return $byClient;
    }

    /**
     * Состояние «просрочки нет» — для клиентов, которым писали в прошлый раз.
     *
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'overdue_amount' => 0.0,
            'total_debt' => 0.0,
            'days_overdue' => 0,
            'oldest_due_date' => null,
            'positions_count' => 0,
            'company_id' => null,
        ];
    }

    /**
     * Предыдущее состояние — из последнего финансового письма по этому клиенту.
     *
     * Источник правды здесь — сам поток писем: письмо и есть запись о том,
     * что клиенту уже сказали. Отдельной таблицы состояния не нужно, и после
     * демонтажа пульта переносить нечего.
     *
     * @return array<string, mixed>|null
     */
    private function previousState(?int $clientUserId): ?array
    {
        if ($clientUserId === null) {
            return null;
        }

        $letter = CrmEmail::query()
            ->where('client_user_id', $clientUserId)
            ->whereIn('origin_event', self::OVERDUE_EVENTS)
            ->latest('id')
            ->first(['id', 'origin_event', 'origin_data']);

        if ($letter === null) {
            return null;
        }

        // Погашение обнуляет состояние: следующая просрочка — снова «возникла».
        if ($letter->origin_event === 'finance.overdue_cleared') {
            return ['overdue_amount' => 0.0, 'days_overdue' => 0];
        }

        $data = (array) $letter->origin_data;

        return [
            'overdue_amount' => (float) ($data['overdue_amount'] ?? 0),
            'days_overdue' => (int) ($data['days_overdue'] ?? 0),
        ];
    }

    /**
     * Повод превращается в письмо — или только считается, если это сухой прогон.
     */
    private function publish(bool $dryRun, Occasion $occasion): void
    {
        if ($dryRun) {
            return;
        }

        $this->stream->captureQuietly($occasion);
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

    private function hasInvoice(int $shipmentId): bool
    {
        return PrintedDocument::query()
            ->where('shipment_id', $shipmentId)
            ->where('file_status', PrintedDocument::FILE_STORED)
            ->where('type', \App\Enums\PrintedDocumentType::INVOICE)
            ->exists();
    }

    /**
     * Клиенты, у которых просрочка была в прошлый раз, — чтобы заметить
     * её погашение, когда в балансе уже ноль.
     *
     * @return array<int, int>
     */
    private function clientsWithPreviousOverdue(): array
    {
        return CrmEmail::query()
            ->whereIn('origin_event', ['finance.overdue_started', 'finance.overdue_grew'])
            ->whereNotNull('client_user_id')
            ->where('created_at', '>=', now()->subDays(90))
            ->distinct()
            ->pluck('client_user_id')
            ->all();
    }
}
