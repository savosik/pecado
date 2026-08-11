<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ShipmentPaymentSchedule;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Оплаты клиента в личном кабинете.
 *
 * Только чтение: платёж заводит 1С. Гейт такой же жёсткий, как у отгрузок —
 * прямое сравнение user_id, без политик: чужой платёж не должен быть виден
 * ни при каких настройках ролей.
 */
class PaymentController extends Controller
{
    private const DIRECTION_LABELS = [
        Payment::DIRECTION_IN => 'Поступление',
        Payment::DIRECTION_OUT => 'Возврат',
    ];

    private const ALLOCATION_LABELS = [
        Payment::ALLOCATION_ALLOCATED => 'Разнесён',
        Payment::ALLOCATION_PARTIAL => 'Разнесён частично',
        Payment::ALLOCATION_ADVANCE => 'Аванс',
    ];

    private const SORTS = ['id', 'date', 'amount', 'unallocated_amount'];

    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * Список оплат. GET /cabinet/payments
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);

        $payments = $query->paginate($context['per_page'])->withQueryString();
        $currency = $this->getUserCurrency($request);

        $payments->getCollection()->transform(fn (Payment $payment) => $this->row($payment, $currency));

        return Inertia::render('User/Cabinet/Payments/Index', [
            'payments' => $payments,
            'filters' => [
                'search' => $context['search'],
                'direction' => $context['directions'],
                'allocation_status' => $context['allocation_statuses'],
                'date_from' => $context['date_from'],
                'date_to' => $context['date_to'],
                'amount_from' => $context['amount_from'],
                'amount_to' => $context['amount_to'],
                'sort_by' => $context['sort_by'],
                'sort_order' => $context['sort_order'],
                'per_page' => $context['per_page'],
            ],
            'directions' => $this->options(self::DIRECTION_LABELS),
            'allocationStatuses' => $this->options(self::ALLOCATION_LABELS),
            'exportEnabled' => (bool) config('search-cabinet.export'),
        ]);
    }

    /**
     * Карточка платежа. GET /cabinet/payments/{payment}
     */
    public function show(Request $request, Payment $payment): InertiaResponse
    {
        $user = $request->user();

        abort_unless($payment->user_id === $user->id, 403);

        $payment->load([
            'company',
            'organization:id,name,legal_name,tax_id,is_stub',
            'allocations.shipment:id,number,erp_number,date,total_amount,paid_amount,payment_status,currency_code',
            // v15.16.0: расшифровка вмещает и заказы (предоплата), и прочие документы
            'allocations.order:id,uuid,number,erp_number,total_amount,prepaid_amount,created_at,erp_created_at',
        ]);

        $currency = $this->getUserCurrency($request);

        return Inertia::render('User/Cabinet/Payments/Show', [
            'payment' => array_merge($this->row($payment, $currency), [
                'document_type' => $payment->document_type,
                'operation_name' => $payment->operation_name,
                'bank_date' => $payment->bank_date instanceof \Illuminate\Support\Carbon
                    ? $payment->bank_date->format('d.m.Y')
                    : null,
                'bank_confirmed_at' => $payment->bank_confirmed_at?->format('d.m.Y H:i'),
                'organization_account' => $payment->organization_account,
                'organization_bank_name' => $payment->organization_bank_name,
                'payer_account' => $payment->payer_account,
                'payer_bank_name' => $payment->payer_bank_name,
                'uip' => $payment->uip,
                'purpose' => $payment->purpose,
                'company' => $payment->company ? [
                    'id' => $payment->company->id,
                    'name' => $payment->company->name,
                    'legal_name' => $payment->company->legal_name,
                    'tax_id' => $payment->company->tax_id,
                ] : null,
                'seller' => $this->sellerPayload($payment),
                'allocations' => $payment->allocations
                    ->sortBy(fn (PaymentAllocation $allocation): int => $allocation->line_number ?? $allocation->id)
                    ->values()
                    ->map(fn (PaymentAllocation $allocation): array => [
                        'id' => $allocation->id,
                        'amount' => (float) $allocation->amount,
                        'amount_converted' => $this->convertAmount((float) $allocation->amount, $payment->currency_code, $currency),
                        // v15.16.0: тип документа расшифровки. `document_label` —
                        // единственное, что можно показать по строке `other`:
                        // такие документы сайт не заводит
                        'target_type' => $allocation->target_type,
                        'target_type_label' => PaymentAllocation::TARGET_LABELS[$allocation->target_type] ?? 'Документ',
                        'document_label' => $allocation->documentLabel(),
                        'shipment' => $allocation->shipment ? [
                            'id' => $allocation->shipment->id,
                            'number' => $allocation->shipment->erp_number
                                ?? $allocation->shipment->number
                                ?? ('#'.$allocation->shipment->id),
                            'date' => $allocation->shipment->date?->format('Y-m-d'),
                            'total_amount' => (float) $allocation->shipment->total_amount,
                            'paid_amount' => (float) $allocation->shipment->paid_amount,
                            'payment_status' => $allocation->shipment->payment_status,
                            'payment_status_label' => $allocation->shipment->payment_status_label,
                        ] : null,
                        'order' => $allocation->order ? [
                            'id' => $allocation->order->id,
                            'number' => $allocation->order->erp_number
                                ?? $allocation->order->number
                                ?? ('#'.$allocation->order->id),
                            'date' => ($allocation->order->erp_created_at ?? $allocation->order->created_at)?->format('Y-m-d'),
                            'total_amount' => (float) $allocation->order->total_amount,
                            'prepaid_amount' => (float) $allocation->order->prepaid_amount,
                        ] : null,
                    ])->all(),
            ]),
            'currency_code' => $currency?->code ?? 'RUB',
        ]);
    }

    /**
     * Календарь оплат. GET /cabinet/payments/calendar?month=YYYY-MM
     *
     * План, а не факт: строится по графику оплаты реализаций («Правила оплаты» 1С),
     * а не по проведённым платежам. Факт клиент смотрит списком в этом же разделе.
     */
    public function calendar(Request $request): InertiaResponse
    {
        $user = $request->user();
        $currency = $this->getUserCurrency($request);

        $month = $this->resolveMonth($request->input('month'));
        $today = Carbon::today();

        $monthly = $this->scheduleQuery($user)
            ->whereBetween('sch.due_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get();

        // Просрочка не привязана к показываемому месяцу: клиент должен видеть её
        // всегда, в каком бы месяце календаря ни находился.
        $overdue = $this->scheduleQuery($user)
            ->whereDate('sch.due_date', '<', $today->toDateString())
            // Закрытые строки в просрочку не идут. Без этого условия клиент видел
            // бы «Просрочено: 5 документов» на нулевую сумму — счётчик считал все
            // прошедшие строки, включая оплаченные. Константа подставляется в SQL,
            // а не биндится: у выражения нет аффинности типа, и SQLite сравнил бы
            // число с привязанной строкой.
            ->whereRaw('sch.amount - sch.paid_amount - sch.prepaid_amount > '.ShipmentPaymentSchedule::EPSILON)
            ->orderBy('sch.due_date')
            ->limit(200)
            ->get();

        $weekAhead = $this->scheduleQuery($user)
            ->whereBetween('sch.due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->get();

        $entries = $monthly->map(fn (object $row): array => $this->scheduleEntry($row, $currency, $today))->all();

        return Inertia::render('User/Cabinet/Payments/Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->monthLabel($month),
            'prevMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'today' => $today->toDateString(),
            'entries' => $entries,
            'overdueEntries' => $overdue->map(fn (object $row): array => $this->scheduleEntry($row, $currency, $today))->all(),
            'summary' => [
                'overdue_amount' => $this->sumUnpaid($overdue, $currency),
                'overdue_count' => $overdue->count(),
                'week_amount' => $this->sumUnpaid($weekAhead, $currency),
                'month_amount' => $this->sumUnpaid($monthly, $currency),
            ],
            'currencyCode' => $currency?->code ?? 'RUB',
        ]);
    }

    /**
     * Строки графика клиента с данными реализации.
     *
     * Джойном, а не через связь: календарю нужны только плоские поля, а грузить
     * реализации со связями ради номера документа — лишние запросы на каждый месяц.
     */
    private function scheduleQuery(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::table('shipment_payment_schedules as sch')
            ->join('shipments as s', 's.id', '=', 'sch.shipment_id')
            ->whereNull('s.deleted_at')
            ->where('s.user_id', $user->id)
            ->orderBy('sch.due_date')
            ->orderByRaw('COALESCE(sch.line_number, 2147483647)')
            ->select([
                'sch.id',
                'sch.shipment_id',
                'sch.due_date',
                'sch.amount',
                'sch.paid_amount',
                // Аванс по заказу закрывает строку наравне с прямым разнесением —
                // так считает ShipmentPaymentSchedule::unpaid_amount и весь остальной
                // расчёт денег. Без этой колонки кабинет показывал бы клиенту
                // просрочку, которой у него нет: 1С разносит на заказы почти
                // половину денег.
                'sch.prepaid_amount',
                'sch.stage_name',
                'sch.line_number',
                's.number as shipment_number',
                's.erp_number as shipment_erp_number',
                's.date as shipment_date',
                's.currency_code',
            ]);
    }

    /**
     * Строка календаря в том виде, в каком её рисует фронт.
     *
     * @return array<string, mixed>
     */
    private function scheduleEntry(object $row, ?Currency $currency, Carbon $today): array
    {
        $unpaid = $this->unpaidOf($row);
        $dueDate = Carbon::parse($row->due_date);
        $isPaid = $unpaid <= ShipmentPaymentSchedule::EPSILON;

        return [
            'id' => $row->id,
            'due_date' => $dueDate->toDateString(),
            'due_date_label' => $dueDate->format('d.m.Y'),
            'amount' => $this->convertAmount((float) $row->amount, $row->currency_code, $currency),
            'unpaid_amount' => $this->convertAmount($unpaid, $row->currency_code, $currency),
            'is_paid' => $isPaid,
            'is_overdue' => ! $isPaid && $dueDate->isBefore($today),
            'stage_name' => $row->stage_name,
            'shipment' => [
                'id' => $row->shipment_id,
                'number' => $row->shipment_erp_number ?? $row->shipment_number ?? ('#'.$row->shipment_id),
                'date_label' => $row->shipment_date ? Carbon::parse($row->shipment_date)->format('d.m.Y') : null,
                'url' => '/cabinet/shipments/'.$row->shipment_id,
            ],
        ];
    }

    /**
     * Сумма остатков к оплате в валюте кабинета.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     */
    private function sumUnpaid(\Illuminate\Support\Collection $rows, ?Currency $currency): float
    {
        return round($rows->sum(function (object $row) use ($currency): float {
            return $this->convertAmount($this->unpaidOf($row), $row->currency_code, $currency);
        }), 2);
    }

    /**
     * Остаток по строке графика.
     *
     * Обе колонки погашения вычитаются обязательно: `paid_amount` — закрытое
     * разнесением платежа на накладную, `prepaid_amount` — закрытое авансом
     * по заказу. Клиенту без разницы, каким документом 1С зачла деньги,
     * а формула без второй колонки завышает его долг в разы.
     */
    private function unpaidOf(object $row): float
    {
        return max(0.0, round(
            (float) $row->amount - (float) $row->paid_amount - (float) ($row->prepaid_amount ?? 0),
            2,
        ));
    }

    /**
     * Месяц из строки YYYY-MM. Мусор в параметре — текущий месяц, а не 500.
     */
    private function resolveMonth(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
            } catch (\Throwable) {
                // Падаем в текущий месяц ниже.
            }
        }

        return Carbon::today()->startOfMonth();
    }

    /**
     * «Август 2026». Carbon::translatedFormat зависит от локали приложения,
     * а месяцы нужны по-русски в любом окружении.
     */
    private function monthLabel(Carbon $month): string
    {
        $names = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $names[$month->month].' '.$month->year;
    }

    /**
     * Экспорт текущей выдачи. GET /cabinet/payments/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        $user = $request->user();
        [$query] = $this->buildIndexQuery($request, $user);
        $currency = $this->getUserCurrency($request);

        $withSeller = (bool) config('erp.organizations.enabled');

        $headers = array_merge(
            ['Номер', 'Дата', 'Направление', 'Контрагент'],
            $withSeller ? ['Получатель'] : [],
            ['Номер по банку', 'Сумма', 'Валюта', 'Сумма в валюте кабинета', 'Разнесено', 'Аванс', 'Реализации'],
        );

        // Генератором по cursor(): выгрузка кабинета идёт потоково, держать
        // в памяти все платежи со связями дороже самой генерации файла.
        $rows = (function () use ($query, $currency, $withSeller) {
            foreach ($query->cursor() as $payment) {
                $shipments = $payment->allocations
                    ->map(fn (PaymentAllocation $allocation): ?string => $allocation->shipment?->erp_number
                        ?: $allocation->shipment?->number)
                    ->filter()
                    ->unique()
                    ->implode(', ');

                yield array_merge(
                    [
                        $payment->number,
                        $payment->date->format('Y-m-d H:i'),
                        self::DIRECTION_LABELS[$payment->direction] ?? $payment->direction,
                        $payment->company?->name ?? '',
                    ],
                    $withSeller ? [$payment->organization?->name ?? 'Не указана'] : [],
                    [
                        $payment->bank_number ?? '',
                        round((float) $payment->amount, 2),
                        $payment->currency_code ?? 'RUB',
                        round($this->convertAmount((float) $payment->amount, $payment->currency_code, $currency), 2),
                        round((float) $payment->allocated_amount, 2),
                        round((float) $payment->unallocated_amount, 2),
                        $shipments,
                    ],
                );
            }
        })();

        $filename = 'payments-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Оплаты');
    }

    /**
     * @return array{0: \Illuminate\Database\Eloquent\Builder<Payment>, 1: array<string, mixed>}
     */
    private function buildIndexQuery(Request $request, User $user): array
    {
        $search = trim((string) $request->input('search', ''));

        $query = Payment::query()
            ->where('user_id', $user->id)
            ->with(['company', 'organization', 'allocations.shipment:id,number,erp_number']);

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('number', 'like', "%{$search}%")
                    ->orWhere('bank_number', 'like', "%{$search}%")
                    ->orWhere('uip', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    // Номер реализации: клиент чаще помнит накладную, а не платёж.
                    ->orWhereHas('allocations.shipment', fn ($shipment) => $shipment
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('erp_number', 'like', "%{$search}%"));
            });
        }

        $directions = $this->multi($request, 'direction', array_keys(self::DIRECTION_LABELS));
        if ($directions !== []) {
            $query->whereIn('direction', $directions);
        }

        $allocationStatuses = $this->multi($request, 'allocation_status', array_keys(self::ALLOCATION_LABELS));
        if ($allocationStatuses !== []) {
            $query->where(function ($inner) use ($allocationStatuses) {
                foreach ($allocationStatuses as $status) {
                    $inner->orWhere(function ($branch) use ($status) {
                        match ($status) {
                            Payment::ALLOCATION_ALLOCATED => $branch->where('unallocated_amount', '<=', Payment::EPSILON),
                            Payment::ALLOCATION_PARTIAL => $branch->where('unallocated_amount', '>', Payment::EPSILON)
                                ->where('allocated_amount', '>', Payment::EPSILON),
                            default => $branch->where('allocated_amount', '<=', Payment::EPSILON)
                                ->where('unallocated_amount', '>', Payment::EPSILON),
                        };
                    });
                }
            });
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        if ($amountFrom = $request->input('amount_from')) {
            $query->where('amount', '>=', $amountFrom);
        }
        if ($amountTo = $request->input('amount_to')) {
            $query->where('amount', '<=', $amountTo);
        }

        $sortBy = $request->input('sort_by', 'date');
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, self::SORTS, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }
        // Вторичная сортировка: платежи одного дня иначе разъезжаются между страницами.
        $query->orderBy('id', 'desc');

        return [$query, [
            'search' => $search,
            'directions' => $directions,
            'allocation_statuses' => $allocationStatuses,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => min(max((int) $request->input('per_page', 15), 5), 100),
        ]];
    }

    /**
     * Мультивыбор, понимающий и скаляр: старые ссылки продолжают работать.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function multi(Request $request, string $key, array $allowed): array
    {
        $input = $request->input($key);

        if ($input === null || $input === '') {
            return [];
        }

        $values = array_map('strval', is_array($input) ? $input : [$input]);

        return array_values(array_intersect(array_unique($values), $allowed));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Payment $payment, ?Currency $currency): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'date' => $payment->date->format('Y-m-d'),
            'date_label' => $payment->date->format('d.m.Y'),
            'direction' => $payment->direction,
            'direction_label' => self::DIRECTION_LABELS[$payment->direction] ?? $payment->direction,
            'bank_number' => $payment->bank_number,
            'bank_confirmed' => (bool) $payment->bank_confirmed,
            'amount' => (float) $payment->amount,
            'amount_converted' => $this->convertAmount((float) $payment->amount, $payment->currency_code, $currency),
            'currency_code' => $payment->currency_code,
            'allocated_amount' => (float) $payment->allocated_amount,
            'unallocated_amount' => (float) $payment->unallocated_amount,
            'allocation_status' => $payment->allocation_status,
            'allocation_status_label' => self::ALLOCATION_LABELS[$payment->allocation_status] ?? '',
            'allocations_count' => $payment->allocations->count(),
            'company_name' => $payment->company?->name,
        ];
    }

    /**
     * Наша организация — получатель платежа. Показ гейтит тот же флаг,
     * что и «Продавца» в реализациях.
     *
     * @return array<string, mixed>|null
     */
    private function sellerPayload(Payment $payment): ?array
    {
        if (! config('erp.organizations.enabled') || $payment->organization === null) {
            return null;
        }

        return [
            'name' => $payment->organization->name,
            'legal_name' => $payment->organization->legal_name,
            'tax_id' => $payment->organization->tax_id,
            'is_stub' => (bool) $payment->organization->is_stub,
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(
            fn ($value, $label) => ['value' => $value, 'label' => $label],
            array_keys($labels),
            $labels
        );
    }

    private function getUserCurrency(Request $request): ?Currency
    {
        return $request->user()?->region?->currency;
    }

    /**
     * Платежи хранятся в валюте 1С, показываются в валюте кабинета.
     * Логика повторяет отгрузки — иначе один и тот же документ показывал бы
     * клиенту разные суммы в разных разделах.
     */
    private function convertAmount(float $amount, ?string $sourceCurrencyCode, ?Currency $targetCurrency): float
    {
        $amountInRub = $amount;

        if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
            $sourceCurrency = Currency::where('code', $sourceCurrencyCode)->first();

            if ($sourceCurrency) {
                $amountInRub = round($amount * (float) $sourceCurrency->exchange_rate, 2);
            }
        }

        if (! $targetCurrency || $targetCurrency->is_base) {
            return $amountInRub;
        }

        return $this->currencyService->convertFromBase($amountInRub, $targetCurrency);
    }
}
