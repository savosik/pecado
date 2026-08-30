<?php

namespace App\Services\Payroll\Invoices;

use App\Models\PayrollInvoiceSettlement;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Мост «накладная → срок оплаты → дата закрывающего платежа → задержка».
 *
 * Даты оплаты в модели нет (shipments.paid_at снесён, регистр несёт только
 * settled_amount), поэтому она восстанавливается: фактические строки регистра
 * `payment_in` с объектом расчётов «Реализация … №» сопоставляются с реализацией
 * по номеру. Закрывающий платёж — первый день, когда накопленные платежи
 * покрыли сумму накладной; задержка — рабочие дни от последней даты графика
 * до этого дня.
 *
 * Строка на реализацию одна; ручная дата РОПа (`manual_*`) проектором не трогается
 * и всегда приоритетнее сопоставленной. Реализация, оплаченная по 1С, но без
 * восстановленной даты, помечается `needs_review` — очередь на ручную разметку.
 */
class PayrollInvoiceSettlementProjector
{
    private const CHUNK = 500;

    /** @var list<string> */
    private const UPSERT_COLUMNS = [
        'shipment_uuid', 'erp_number', 'number_key', 'user_id', 'company_id', 'personal_manager_id',
        'shipped_on', 'total_amount', 'due_on', 'due_source', 'matched_paid_amount', 'matched_settled_on',
        'payments', 'payment_status', 'settled_on', 'settled_source', 'delay_calendar_days',
        'delay_working_days', 'needs_review', 'computed_at', 'updated_at',
    ];

    public function __construct(
        private readonly InvoiceNumberNormalizer $numbers,
        private readonly WorkingCalendar $calendar,
    ) {}

    /**
     * Полный проход по реализациям с даты документа не раньше $since.
     *
     * @return array{shipments: int, matched: int, needs_review: int, managers: list<int>}
     */
    public function rebuild(CarbonInterface $since): array
    {
        $stats = self::emptyStats();

        Shipment::query()
            ->withoutInternalOrganizations()
            ->where('erp_created_at', '>=', CarbonImmutable::instance($since)->startOfDay())
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $shipments) use (&$stats): void {
                $stats = self::mergeStats($stats, $this->projectMany($shipments));
            });

        return $stats;
    }

    /**
     * Реализации партнёров за окно ребилда — инкремент по событию регистра.
     *
     * @param  list<int>  $userIds
     * @return array{shipments: int, matched: int, needs_review: int, managers: list<int>}
     */
    public function projectPartners(array $userIds, ?CarbonInterface $since = null): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($userIds === []) {
            return self::emptyStats();
        }

        $since ??= now()->subMonths((int) config('payroll.invoices.rebuild_months', 6))->startOfMonth();

        $shipments = Shipment::query()
            ->withoutInternalOrganizations()
            ->whereIn('user_id', $userIds)
            ->where('erp_created_at', '>=', CarbonImmutable::instance($since)->startOfDay())
            ->orderBy('id')
            ->get();

        return $this->projectMany($shipments);
    }

    public function projectShipment(Shipment $shipment): ?PayrollInvoiceSettlement
    {
        $this->projectMany(new Collection([$shipment]));

        return PayrollInvoiceSettlement::query()->where('shipment_id', $shipment->getKey())->first();
    }

    /**
     * Ручная дата закрытия от РОПа — приоритетнее сопоставления, ребилд её не трогает.
     */
    public function markManual(PayrollInvoiceSettlement $row, CarbonInterface $settledOn, ?string $comment, User $actor): PayrollInvoiceSettlement
    {
        $row->manual_settled_on = \Illuminate\Support\Carbon::instance($settledOn)->startOfDay();
        $row->manual_comment = $comment;
        $row->manual_by_user_id = (int) $actor->getKey();
        $row->manual_set_at = now();

        return $this->applyEffective($row);
    }

    public function clearManual(PayrollInvoiceSettlement $row): PayrollInvoiceSettlement
    {
        $row->manual_settled_on = null;
        $row->manual_comment = null;
        $row->manual_by_user_id = null;
        $row->manual_set_at = null;

        return $this->applyEffective($row);
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return array{shipments: int, matched: int, needs_review: int, managers: list<int>}
     */
    private function projectMany(Collection $shipments): array
    {
        $stats = self::emptyStats();

        if ($shipments->isEmpty()) {
            return $stats;
        }

        $uuids = $shipments->pluck('uuid')->filter()->values()->all();
        $userIds = $shipments->pluck('user_id')->filter()->unique()->map('intval')->values()->all();
        $companyIds = $shipments->pluck('company_id')->filter()->unique()->map('intval')->values()->all();

        $dueByUuid = $this->dueDates($uuids);
        $paymentsByKey = $this->payments($userIds, $companyIds, 'Реализация%', fn (?string $name): ?string => $this->numbers->fromObjectName($name), 'invoice');
        $orderPaymentsByKey = $this->payments($userIds, $companyIds, 'Заказ клиента%', fn (?string $name): ?string => $this->numbers->orderKeyFromObjectName($name), 'order');
        $orderKeysByShipment = $this->orderKeys($shipments->modelKeys());
        $managerByUser = $userIds === []
            ? []
            : User::query()->whereIn('id', $userIds)->pluck('personal_manager_id', 'id')->all();

        $existing = PayrollInvoiceSettlement::query()
            ->whereIn('shipment_id', $shipments->modelKeys())
            ->get()
            ->keyBy('shipment_id');

        $now = now();
        $rows = [];

        foreach ($shipments as $shipment) {
            /** @var PayrollInvoiceSettlement|null $current */
            $current = $existing->get($shipment->getKey());
            $key = $this->numbers->key($shipment->erp_number);
            $total = (float) $shipment->total_amount;

            [$dueOn, $dueSource] = $this->dueFor($shipment, $dueByUuid);
            $shippedOn = $shipment->erp_created_at?->toDateString() ?? $shipment->date?->toDateString();

            // Платежи по самой накладной плюс авансы по её заказу — одним потоком
            // по дате: закрывает тот платёж, на котором накопленная сумма покрыла накладную.
            $candidates = $key === null ? [] : ($paymentsByKey[$key] ?? []);
            foreach ($orderKeysByShipment[(int) $shipment->getKey()] ?? [] as $orderKey) {
                $candidates = array_merge($candidates, $orderPaymentsByKey[$orderKey] ?? []);
            }
            usort($candidates, fn (array $a, array $b): int => strcmp($a['date'], $b['date']) ?: strcmp($a['entry_uuid'], $b['entry_uuid']));

            [$matchedOn, $paid, $payments] = $this->matchPayments($candidates, $total);

            // Накладная не может закрыться раньше, чем появилась: аванс по заказу
            // закрывает её датой отгрузки.
            if ($matchedOn !== null && $shippedOn !== null && $matchedOn < $shippedOn) {
                $matchedOn = $shippedOn;
            }

            $manualOn = $current?->manual_settled_on?->toDateString();
            $settledOn = $manualOn ?? $matchedOn;
            $settledSource = $manualOn !== null
                ? PayrollInvoiceSettlement::SOURCE_MANUAL
                : ($matchedOn !== null ? PayrollInvoiceSettlement::SOURCE_MATCHED : null);

            [$calendarDays, $workingDays] = $this->delays($dueOn, $settledOn);

            $needsReview = $shipment->payment_status === Shipment::PAYMENT_PAID
                && $settledOn === null
                && $total > SettlementEntry::EPSILON;

            $managerId = $shipment->user_id === null ? null : ($managerByUser[(int) $shipment->user_id] ?? null);

            $rows[] = [
                'shipment_id' => (int) $shipment->getKey(),
                'shipment_uuid' => (string) $shipment->uuid,
                'erp_number' => $shipment->erp_number,
                'number_key' => $key,
                'user_id' => $shipment->user_id,
                'company_id' => $shipment->company_id,
                'personal_manager_id' => $managerId === null ? null : (int) $managerId,
                'shipped_on' => $shippedOn,
                'total_amount' => round($total, 2),
                'due_on' => $dueOn,
                'due_source' => $dueSource,
                'matched_paid_amount' => round($paid, 2),
                'matched_settled_on' => $matchedOn,
                'payments' => $payments === [] ? null : json_encode($payments, JSON_UNESCAPED_UNICODE),
                'payment_status' => $shipment->payment_status,
                'settled_on' => $settledOn,
                'settled_source' => $settledSource,
                'delay_calendar_days' => $calendarDays,
                'delay_working_days' => $workingDays,
                'needs_review' => $needsReview,
                'computed_at' => $now,
                'created_at' => $current === null ? $now : ($current->created_at ?? $now),
                'updated_at' => $now,
            ];

            $stats['shipments']++;
            if ($settledOn !== null) {
                $stats['matched']++;
            }
            if ($needsReview) {
                $stats['needs_review']++;
            }
            if ($managerId !== null) {
                $stats['managers'][] = (int) $managerId;
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            PayrollInvoiceSettlement::query()->upsert($chunk, ['shipment_id'], self::UPSERT_COLUMNS);
        }

        $stats['managers'] = array_values(array_unique($stats['managers']));

        return $stats;
    }

    /**
     * Срок оплаты: последняя дата графика регистра, иначе колонка реализации.
     *
     * @param  array<string, string>  $dueByUuid
     * @return array{0: string|null, 1: string|null}
     */
    private function dueFor(Shipment $shipment, array $dueByUuid): array
    {
        $scheduled = $dueByUuid[(string) $shipment->uuid] ?? null;

        if ($scheduled !== null) {
            return [CarbonImmutable::parse($scheduled)->toDateString(), PayrollInvoiceSettlement::DUE_SCHEDULE];
        }

        if ($shipment->payment_due_date !== null) {
            return [$shipment->payment_due_date->toDateString(), PayrollInvoiceSettlement::DUE_SHIPMENT_COLUMN];
        }

        return [null, null];
    }

    /**
     * @param  list<string>  $uuids
     * @return array<string, string> document_uuid → последняя плановая дата
     */
    private function dueDates(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return SettlementEntry::query()
            ->plans()
            ->whereIn('document_uuid', $uuids)
            ->groupBy('document_uuid')
            ->select('document_uuid', DB::raw('MAX(date) as due_on'))
            ->pluck('due_on', 'document_uuid')
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * Входящие платежи партнёров по объекту расчётов, сгруппированные по ключу номера.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $companyIds
     * @param  \Closure(?string): ?string  $keyOf  ключ из имени объекта расчётов
     * @return array<string, list<array{entry_uuid: string, date: string, amount: float, document_number: string|null, kind: string}>>
     */
    private function payments(array $userIds, array $companyIds, string $objectPrefix, \Closure $keyOf, string $kind): array
    {
        if ($userIds === [] && $companyIds === []) {
            return [];
        }

        $rows = SettlementEntry::query()
            ->facts()
            ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
            ->where(function ($query) use ($userIds, $companyIds): void {
                if ($userIds !== []) {
                    $query->orWhereIn('user_id', $userIds);
                }
                if ($companyIds !== []) {
                    $query->orWhereIn('company_id', $companyIds);
                }
            })
            ->where('settlement_object_name', 'like', $objectPrefix)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['uuid', 'date', 'amount', 'settlement_object_name', 'document_number']);

        $byKey = [];

        foreach ($rows as $row) {
            $key = $keyOf($row->settlement_object_name);
            if ($key === null) {
                continue;
            }

            $byKey[$key][] = [
                'entry_uuid' => (string) $row->uuid,
                'date' => $row->date?->toDateString() ?? '',
                'amount' => abs((float) $row->amount),
                'document_number' => $row->document_number,
                'kind' => $kind,
            ];
        }

        return $byKey;
    }

    /**
     * Заказы, из которых собраны реализации: shipment_id → ключи номеров заказов.
     *
     * Связь мягкая — shipment_items.order_uuid, без FK; заказ может быть отгружен
     * частями, и его аванс засчитывается каждой реализации. Это осознанно: аванс
     * означает «деньги пришли до отгрузки», а не «ровно за эту накладную».
     *
     * @param  list<int|string>  $shipmentIds
     * @return array<int, list<string>>
     */
    private function orderKeys(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $rows = DB::table('shipment_items')
            ->join('orders', 'orders.uuid', '=', 'shipment_items.order_uuid')
            ->whereIn('shipment_items.shipment_id', $shipmentIds)
            ->whereNotNull('shipment_items.order_uuid')
            ->whereNull('orders.deleted_at')
            ->distinct()
            ->get(['shipment_items.shipment_id', 'orders.erp_number']);

        $byShipment = [];

        foreach ($rows as $row) {
            $key = $this->numbers->key($row->erp_number);
            if ($key !== null) {
                $byShipment[(int) $row->shipment_id][] = $key;
            }
        }

        return $byShipment;
    }

    /**
     * Закрывающий платёж: первый день, когда накопленные платежи покрыли накладную.
     *
     * @param  list<array{entry_uuid: string, date: string, amount: float, document_number: string|null, kind: string}>  $payments
     * @return array{0: string|null, 1: float, 2: list<array<string, mixed>>}
     */
    private function matchPayments(array $payments, float $total): array
    {
        $paid = 0.0;
        $settledOn = null;

        foreach ($payments as $payment) {
            $paid += $payment['amount'];

            if ($settledOn === null && $total > SettlementEntry::EPSILON && $paid >= $total - SettlementEntry::EPSILON) {
                $settledOn = $payment['date'] !== '' ? $payment['date'] : null;
            }
        }

        return [$settledOn, $paid, $payments];
    }

    /**
     * @return array{0: int|null, 1: int|null} календарных и рабочих дней задержки
     */
    private function delays(?string $dueOn, ?string $settledOn): array
    {
        if ($dueOn === null || $settledOn === null) {
            return [null, null];
        }

        $due = CarbonImmutable::parse($dueOn)->startOfDay();
        $settled = CarbonImmutable::parse($settledOn)->startOfDay();

        if ($settled->lte($due)) {
            return [0, 0];
        }

        return [
            (int) $due->diffInDays($settled),
            $this->calendar->workingDaysBetween($due, $settled),
        ];
    }

    /**
     * Пересчитать действующую дату и задержку после ручной правки и сохранить.
     */
    private function applyEffective(PayrollInvoiceSettlement $row): PayrollInvoiceSettlement
    {
        $manualOn = $row->manual_settled_on?->toDateString();
        $matchedOn = $row->matched_settled_on?->toDateString();
        $settledOn = $manualOn ?? $matchedOn;

        [$calendarDays, $workingDays] = $this->delays($row->due_on?->toDateString(), $settledOn);

        $row->settled_on = $settledOn === null ? null : \Illuminate\Support\Carbon::parse($settledOn)->startOfDay();
        $row->settled_source = $manualOn !== null
            ? PayrollInvoiceSettlement::SOURCE_MANUAL
            : ($matchedOn !== null ? PayrollInvoiceSettlement::SOURCE_MATCHED : null);
        $row->delay_calendar_days = $calendarDays;
        $row->delay_working_days = $workingDays;
        $row->needs_review = $row->payment_status === Shipment::PAYMENT_PAID
            && $settledOn === null
            && (float) $row->total_amount > SettlementEntry::EPSILON;
        $row->computed_at = now();
        $row->save();

        return $row;
    }

    /**
     * @return array{shipments: int, matched: int, needs_review: int, managers: list<int>}
     */
    private static function emptyStats(): array
    {
        return ['shipments' => 0, 'matched' => 0, 'needs_review' => 0, 'managers' => []];
    }

    /**
     * @param  array{shipments: int, matched: int, needs_review: int, managers: list<int>}  $a
     * @param  array{shipments: int, matched: int, needs_review: int, managers: list<int>}  $b
     * @return array{shipments: int, matched: int, needs_review: int, managers: list<int>}
     */
    private static function mergeStats(array $a, array $b): array
    {
        return [
            'shipments' => $a['shipments'] + $b['shipments'],
            'matched' => $a['matched'] + $b['matched'],
            'needs_review' => $a['needs_review'] + $b['needs_review'],
            'managers' => array_values(array_unique(array_merge($a['managers'], $b['managers']))),
        ];
    }
}
