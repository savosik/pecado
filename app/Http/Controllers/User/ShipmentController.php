<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use App\Support\Search\EmptyResultSuggestion;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\MatchSourceResolver;
use App\Support\Search\QueryRouter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController extends Controller
{
    private const STATUS_LABELS = [
        'new' => 'Новая',
        'completed' => 'Выполнена',
        'cancelled' => 'Отменена',
        'in_progress' => 'В обработке',
    ];

    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * Список отгрузок текущего пользователя.
     * GET /cabinet/shipments
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);
        $search = $context['search'];
        $perPage = $context['per_page'];

        $shipments = $query->paginate($perPage)->withQueryString();
        $currency = $this->getUserCurrency($request);
        $financeEnabled = \App\Support\Cabinet\CabinetFinance::enabledFor($request->user());

        $shipments->getCollection()->transform(function ($shipment) use ($currency, $search, $financeEnabled) {
            $totalConverted = $this->convertAmount((float) $shipment->total_amount, $shipment->currency_code, $currency);

            $match = MatchSourceResolver::resolve(
                $shipment,
                $search,
                directFields: [
                    ['field' => 'number', 'source' => 'number'],
                    ['field' => 'erp_number', 'source' => 'number'],
                    ['field' => 'uuid', 'source' => 'number'],
                    ['field' => 'tax_id', 'source' => 'company'],
                ],
                relationFields: [
                    ['relation' => 'company', 'field' => 'name', 'source' => 'company'],
                ],
                itemFields: [
                    ['relation' => 'items', 'field' => 'product_name_snapshot', 'source' => 'composition'],
                    ['relation' => 'items', 'field' => 'brand_name_snapshot', 'source' => 'composition'],
                ],
            );

            return [
                'id' => $shipment->id,
                'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
                'tax_id' => $shipment->tax_id,
                'date' => $shipment->date?->format('Y-m-d'),
                'updated_at' => $shipment->updated_at?->format('d.m.Y H:i'),
                'status' => $shipment->status,
                'status_label' => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                'currency_code' => $shipment->currency_code,
                'total_amount' => $shipment->total_amount,
                'total_converted' => $totalConverted,
                // Оплата — денормализованные поля, их ведёт PaymentAllocationService.
                // Считать на лету нельзя: экспорт идёт cursor()-ом, а фильтр —
                // по колонке. Закрыты флагом: цифры долга не сверены с 1С.
                ...($financeEnabled ? [
                    'payment_status' => $shipment->payment_status,
                    'payment_status_label' => $shipment->payment_status_label,
                    'paid_amount' => (float) $shipment->paid_amount,
                    'unpaid_amount' => $shipment->unpaid_amount,
                ] : []),
                'items_count' => $shipment->items->count(),
                'company' => $shipment->company ? [
                    'id' => $shipment->company->id,
                    'name' => $shipment->company->name,
                ] : null,
                'match_source' => $match['source'],
                'match_snippet' => $match['snippet'],
            ];
        });

        $suggestion = $shipments->total() === 0
            ? EmptyResultSuggestion::build($search, $this->activeFiltersForSuggestion($context))
            : null;

        return Inertia::render('User/Cabinet/Shipments/Index', [
            'shipments' => $shipments,
            'filters' => [
                'search' => $search,
                'status' => $context['selected_statuses'],
                'payment_status' => $context['payment_statuses'],
                'company_id' => $context['company_id'] ? (string) $context['company_id'] : '',
                'order_uuid' => $context['order_uuid'] ?: null,
                'brand_ids' => $context['brand_ids'],
                'date_from' => $context['date_from'],
                'date_to' => $context['date_to'],
                'amount_from' => $context['amount_from'],
                'amount_to' => $context['amount_to'],
                'sort_by' => $context['sort_by'],
                'sort_order' => $context['sort_order'],
                'per_page' => $perPage,
            ],
            'statuses' => array_map(
                fn ($k, $v) => ['value' => $k, 'label' => $v],
                array_keys(self::STATUS_LABELS),
                self::STATUS_LABELS
            ),
            // Пустой справочник — фильтр «Оплата» на странице не рисуется вовсе.
            'paymentStatuses' => $financeEnabled ? [
                ['value' => Shipment::PAYMENT_UNPAID, 'label' => 'Не оплачена'],
                ['value' => Shipment::PAYMENT_PARTIAL, 'label' => 'Оплачена частично'],
                ['value' => Shipment::PAYMENT_PAID, 'label' => 'Оплачена'],
                ['value' => Shipment::PAYMENT_OVERPAID, 'label' => 'Переплата'],
            ] : [],
            'companies' => $user->companies()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (\App\Models\Company $c) => ['value' => (string) $c->id, 'label' => $c->name]),
            'exportEnabled' => (bool) config('search-cabinet.export'),
            'suggestion' => $suggestion,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function activeFiltersForSuggestion(array $context): array
    {
        $labels = [];
        if (! empty($context['selected_statuses'])) {
            $labels['Статус'] = implode(', ', $context['selected_statuses']);
        }
        if (! empty($context['order_uuid'])) {
            $labels['UUID заказа'] = (string) $context['order_uuid'];
        }
        if (! empty($context['brand_ids'])) {
            $labels['Бренд'] = implode(', ', $context['brand_ids']);
        }
        if (! empty($context['date_from']) || ! empty($context['date_to'])) {
            $labels['Дата'] = trim(($context['date_from'] ?? '').'…'.($context['date_to'] ?? ''));
        }
        if (! empty($context['amount_from']) || ! empty($context['amount_to'])) {
            $labels['Сумма'] = trim(($context['amount_from'] ?? '').'…'.($context['amount_to'] ?? ''));
        }

        return $labels;
    }

    /**
     * Экспорт текущей выдачи в CSV/XLSX. PR 5.2.
     * GET /cabinet/shipments/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        $user = $request->user();
        [$query] = $this->buildIndexQuery($request, $user);
        $currency = $this->getUserCurrency($request);

        // v15.8.0: колонка «Продавец». Пустое значение выводим словами: пустая ячейка
        // в Excel читается как потеря данных, а не как «организации нет».
        $withSeller = (bool) config('erp.organizations.enabled');
        // Колонки оплаты — под тем же флагом, что и экран: выгрузка не должна быть
        // обходным путём к цифрам, которые мы решили клиенту не показывать.
        $financeEnabled = \App\Support\Cabinet\CabinetFinance::enabledFor($request->user());

        $headers = array_merge(
            ['Номер', 'Статус', 'Дата отгрузки', 'Контрагент'],
            $withSeller ? ['Продавец'] : [],
            ['Позиций', 'Сумма', 'Валюта', 'Сумма в валюте кабинета'],
            $financeEnabled ? ['Статус оплаты', 'Оплачено', 'Остаток к оплате'] : [],
        );

        // with('organization') в buildIndexQuery — иначе колонка даст N+1 на выгрузке
        $rows = (function () use ($query, $currency, $withSeller, $financeEnabled) {
            foreach ($query->cursor() as $shipment) {
                $totalConverted = $this->convertAmount((float) $shipment->total_amount, $shipment->currency_code, $currency);
                yield array_merge(
                    [
                        $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
                        self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                        $shipment->date?->format('Y-m-d'),
                        $shipment->company?->name ?? '',
                    ],
                    $withSeller ? [$shipment->organization?->name ?? 'Не указана'] : [],
                    [
                        $shipment->items->count(),
                        round((float) $shipment->total_amount, 2),
                        $shipment->currency_code ?? 'RUB',
                        round((float) $totalConverted, 2),
                    ],
                    $financeEnabled ? [
                        $shipment->payment_status_label,
                        round((float) $shipment->paid_amount, 2),
                        round($shipment->unpaid_amount, 2),
                    ] : [],
                );
            }
        })();

        $filename = 'shipments-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Отгрузки');
    }

    private function buildIndexQuery(Request $request, User $user): array
    {
        $search = trim((string) $request->input('search', ''));

        $query = Shipment::query()
            ->where('user_id', $user->id)
            ->with(['company', 'organization', 'items.product']);

        if ($search !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $search);
            $queryType = QueryRouter::classify($search);

            $fuzzyShipmentIds = FuzzyDocumentMatcher::isApplicable($search, $queryType)
                ? FuzzyDocumentMatcher::findDocumentIds(
                    $search,
                    ShipmentItem::class,
                    'shipment_id',
                    'shipment',
                    $user->id,
                )
                : [];

            $query->where(function ($q) use ($search, $normalized, $queryType, $fuzzyShipmentIds) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");

                // Нормализованная форма номера: 29УТ-003413 ≡ 29УТ003413 (C-4.1).
                if ($normalized !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                    $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }

                // Состав: name/sku/code товара (расширение текущего поиска).
                $q->orWhereHas('items.product', function ($p) use ($search) {
                    $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });

                // Бренд в составе (C-4.2).
                $q->orWhereHas('items.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

                // Штрихкод (C-4.3, точное совпадение).
                if ($queryType === QueryRouter::TYPE_BARCODE) {
                    $q->orWhereHas('items.product.barcodes', fn ($b) => $b->where('barcode', $search));
                }

                // Fuzzy через Meilisearch (PR 4.2, флаг CABINET_SEARCH_FUZZY_DOCUMENTS).
                if (! empty($fuzzyShipmentIds)) {
                    $q->orWhereIn('id', $fuzzyShipmentIds);
                }
            });
        }

        // Статус — поддерживаем скаляр (старое поведение) и массив (multi-select).
        $statusInput = $request->input('status');
        if (is_array($statusInput)) {
            $statuses = array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''));
            if (count($statuses) > 0) {
                $query->whereIn('status', $statuses);
            }
        } elseif ($statusInput) {
            $query->where('status', $statusInput);
        }

        // Фильтр по UUID связанного заказа (C-4.6): «Все отгрузки по заказу».
        if ($orderUuid = $request->input('order_uuid')) {
            $query->whereHas('items', fn ($q) => $q->where('order_uuid', $orderUuid));
        }

        // Фильтр по бренду в составе (C-4.2 как фасет): brand_ids[].
        $brandIds = array_values(array_filter(
            array_map('intval', (array) $request->input('brand_ids', [])),
            fn ($id) => $id > 0,
        ));
        if (count($brandIds) > 0) {
            $query->whereHas('items.product', fn ($p) => $p->whereIn('brand_id', $brandIds));
        }

        // Контрагент: у клиента их может быть несколько юрлиц, и бухгалтерия
        // сверяет отгрузки по каждому отдельно.
        $companyId = $request->integer('company_id') ?: null;
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        // Статус оплаты (v15.11.0) — мультивыбор по индексу
        // shipments_user_payment_status_index.
        $paymentStatuses = array_values(array_intersect(
            array_map('strval', (array) $request->input('payment_status', [])),
            Shipment::PAYMENT_STATUSES,
        ));
        if (count($paymentStatuses) > 0) {
            $query->whereIn('payment_status', $paymentStatuses);
        }

        // Фильтр по дате
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        // Фильтр по сумме
        if ($amountFrom = $request->input('amount_from')) {
            $query->where('total_amount', '>=', $amountFrom);
        }
        if ($amountTo = $request->input('amount_to')) {
            $query->where('total_amount', '<=', $amountTo);
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowed = ['id', 'date', 'total_amount', 'status'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $selectedStatuses = is_array($statusInput)
            ? array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''))
            : ($statusInput ? [(string) $statusInput] : []);

        return [$query, [
            'search' => $search,
            'selected_statuses' => $selectedStatuses,
            'payment_statuses' => $paymentStatuses,
            'company_id' => $companyId,
            'order_uuid' => $orderUuid,
            'brand_ids' => $brandIds,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => $perPage,
        ]];
    }

    /**
     * Просмотр отгрузки.
     * GET /cabinet/shipments/{shipment}
     */
    public function show(Request $request, Shipment $shipment): InertiaResponse
    {
        $user = $request->user();

        abort_unless($shipment->user_id === $user->id, 403);

        // is_stub обязателен в выборке: без него sellerPayload() покажет клиенту
        // UUID вместо названия продавца
        $shipment->load([
            'company',
            'organization:id,name,legal_name,tax_id,is_stub',
            'items.product',
            'items.product.brand',
        ]);

        $currency = $this->getUserCurrency($request);
        $financeEnabled = \App\Support\Cabinet\CabinetFinance::enabledFor($request->user());

        $orderUuids = $shipment->items()
            ->whereNotNull('order_uuid')
            ->pluck('order_uuid')
            ->unique()
            ->values();

        $relatedOrders = $orderUuids->isNotEmpty()
            ? Order::withoutGlobalScopes()
                ->whereIn('uuid', $orderUuids)
                ->with(['company'])
                ->withCount(['items'])
                ->withShipmentsCount()
                ->addSelect([
                    'original_total_amount' => OrderItem::selectRaw('COALESCE(SUM(base_price * quantity), 0)')
                        ->whereColumn('order_id', 'orders.id'),
                ])
                ->get()
            : collect();

        $totalConverted = $this->convertAmount((float) $shipment->total_amount, $shipment->currency_code, $currency);

        // Получить деталь просрочки для этой реализации (если есть)
        $overdueDetail = null;
        if ($shipment->uuid) {
            $overdueDetail = ContractorBalanceOverdueDetail::whereHas(
                'contractorBalance',
                fn ($q) => $q->where('user_id', $user->id)
            )->where('shipment_uuid', $shipment->uuid)->first();
        }

        $ordersByUuid = $relatedOrders->keyBy('uuid');

        // Платежи, разнесённые на эту реализацию. Загружаем отдельно, а не
        // через with() выше: связь нужна только на карточке.
        $shipment->load([
            'paymentAllocations.payment:id,number,date,direction,currency_code',
            // v15.12.0: график оплаты — план рядом с фактом на одной карточке.
        ]);

        return Inertia::render('User/Cabinet/Shipments/Show', [
            'shipment' => [
                'id' => $shipment->id,
                'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
                'tax_id' => $shipment->tax_id,
                'date' => $shipment->date?->format('Y-m-d'),
                // v15.16.0: счёт-фактура из 1С — нужна бухгалтерии клиента.
                // v15.16.1: клиенту показываем ПЕЧАТНЫЙ номер — он сверяет по бумаге,
                // а не по внутреннему номеру базы 1С
                'invoice_number' => $shipment->invoice_number_display ?: $shipment->invoice_number,
                'invoice_date' => $shipment->invoice_date?->format('d.m.Y'),
                'updated_at' => $shipment->updated_at?->format('d.m.Y H:i'),
                'status' => $shipment->status,
                'status_label' => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                'currency_code' => $shipment->currency_code,
                'total_amount' => $shipment->total_amount,
                'total_converted' => $totalConverted,
                // Оплата, разнесение и график закрыты флагом cabinet.finance_enabled:
                // остаток по документу систематически больше реального долга, пока
                // цифры не сверены с 1С — клиенту такое показывать нельзя.
                ...($financeEnabled ? [
                    'payment_status' => $shipment->payment_status,
                    'payment_status_label' => $shipment->payment_status_label,
                    'paid_amount' => (float) $shipment->paid_amount,
                    'unpaid_amount' => $shipment->unpaid_amount,
                    'payments' => $shipment->paymentAllocations
                        ->filter(fn ($allocation) => $allocation->payment !== null)
                        ->sortByDesc(fn ($allocation) => $allocation->payment->date)
                        ->values()
                        ->map(fn ($allocation) => [
                            'id' => $allocation->payment->id,
                            'number' => $allocation->payment->number,
                            'date' => $allocation->payment->date?->format('d.m.Y'),
                            'direction' => $allocation->payment->direction,
                            'direction_label' => $allocation->payment->direction === 'out' ? 'Возврат' : 'Поступление',
                            'amount' => (float) $allocation->amount,
                            'amount_converted' => $this->convertAmount(
                                (float) $allocation->amount,
                                $allocation->payment->currency_code,
                                $currency,
                            ),
                        ])->all(),
                    'payment_schedule' => \App\Support\Payments\PaymentSchedulePresenter::forShipment(
                        $shipment,
                        fn (float $amount): float => $this->convertAmount($amount, $shipment->currency_code, $currency),
                    ),
                ] : []),
                'company' => $shipment->company ? [
                    'id' => $shipment->company->id,
                    'name' => $shipment->company->name,
                    'legal_name' => $shipment->company->legal_name,
                    'tax_id' => $shipment->company->tax_id,
                ] : null,
                // v15.8.0: продавец по накладной — наше юрлицо. Для клиента это самое
                // заметное место: именно по реализации он сверяет документ.
                'seller' => $this->sellerPayload($shipment),
                'items' => $shipment->items->map(function ($item) use ($currency, $ordersByUuid) {
                    $priceConverted = $this->convertAmount((float) $item->price, null, $currency);
                    $totalConverted = $this->convertAmount((float) $item->total, null, $currency);
                    $order = $item->order_uuid ? $ordersByUuid->get($item->order_uuid) : null;

                    return [
                        'id' => $item->id,
                        'order_id' => $order?->id,
                        'order_number' => $order ? ($order->erp_number ?? $order->number ?? ('#'.$order->id)) : null,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'price_converted' => $priceConverted,
                        'auto_discount_percent' => $item->auto_discount_percent,
                        'manual_discount_percent' => $item->manual_discount_percent,
                        'total' => $item->total,
                        'total_converted' => $totalConverted,
                        'vat_rate' => $item->vat_rate,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'slug' => $item->product->slug,
                            'image_url' => $item->product->getFirstMediaUrl('main'),
                            'brand' => $item->product->brand ? ['name' => $item->product->brand->name] : null,
                        ] : null,
                    ];
                }),
            ],
            'related_orders' => $relatedOrders->map(function ($order) use ($currency) {
                $originalTotalAmount = (float) ($order->original_total_amount ?? 0);

                return [
                    'id' => $order->id,
                    'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                    'uuid' => $order->uuid,
                    'type' => $order->type?->value,
                    'status' => $order->status?->value,
                    'status_label' => $order->status?->label() ?? 'Неизвестно',
                    'company' => $order->company ? ['id' => $order->company->id, 'name' => $order->company->name] : null,
                    'items_count' => $order->items_count,
                    'shipments_count' => $order->shipments_count,
                    'total_amount' => $order->total_amount,
                    'total_converted' => $this->convertAmount((float) $order->total_amount, $order->currency_code, $currency),
                    'original_total_amount' => $originalTotalAmount,
                    'original_total_converted' => $this->convertAmount($originalTotalAmount, $order->currency_code, $currency),
                    'currency_code' => $order->currency_code,
                    'erp_created_at' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
                    'erp_updated_at' => ($order->erp_updated_at ?? $order->updated_at)?->format('d.m.Y H:i'),
                ];
            }),
            // Просрочка из 1С — тоже под флагом: это про долг клиента.
            'overdue_detail' => $financeEnabled && $overdueDetail ? [
                'shipment_uuid' => $overdueDetail->shipment_uuid,
                'amount' => $overdueDetail->amount,
                'due_date' => $overdueDetail->due_date?->format('Y-m-d'),
            ] : null,
        ]);
    }

    /**
     * Продавец по накладной — наше юрлицо, от имени которого проведена реализация.
     *
     * `null` в трёх случаях, и во всех фронт не показывает блок: выключен флаг,
     * организация не пришла, либо это заглушка — у неё вместо названия лежит UUID.
     *
     * @return array<string, mixed>|null
     */
    private function sellerPayload(Shipment $shipment): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $shipment->organization;

        if (! $organization || $organization->is_stub) {
            return null;
        }

        return [
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'tax_id' => $organization->tax_id,
        ];
    }

    /**
     * Получить текущую валюту пользователя через регион.
     */
    private function getUserCurrency(Request $request): ?Currency
    {
        return $request->user()?->region?->currency;
    }

    /**
     * Конвертировать сумму в валюту пользователя.
     * Отгрузки хранятся в валюте 1С, но отображаются в валюте пользователя.
     */
    private function convertAmount(float $amount, ?string $sourceCurrencyCode, ?Currency $targetCurrency): float
    {
        if (! $targetCurrency || $targetCurrency->is_base) {
            // Если целевая валюта — базовая (RUB), возвращаем как есть
            // Но если источник не RUB — нужно конвертировать в RUB через курс источника
            if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
                $sourceCurrency = Currency::where('code', $sourceCurrencyCode)->first();
                if ($sourceCurrency) {
                    // amount in source_currency → RUB: amount * exchange_rate
                    return round($amount * (float) $sourceCurrency->exchange_rate, 2);
                }
            }

            return $amount;
        }

        // Сначала перевести в RUB (если источник не RUB)
        $amountInRub = $amount;
        if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
            $sourceCurrency = Currency::where('code', $sourceCurrencyCode)->first();
            if ($sourceCurrency) {
                $amountInRub = round($amount * (float) $sourceCurrency->exchange_rate, 2);
            }
        }

        // Затем конвертировать из RUB в целевую валюту
        return $this->currencyService->convertFromBase($amountInRub, $targetCurrency);
    }

    /**
     * Скачать состав отгрузки в Excel (XLSX).
     * GET /cabinet/shipments/{shipment}/items/export
     */
    public function exportItems(Request $request, Shipment $shipment, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $user = $request->user();
        abort_unless($shipment->user_id === $user->id, 403);

        $shipment->load(['items.product:id,name,sku', 'items.order:id,uuid,number,erp_number']);

        $userCurrency = $this->getUserCurrency($request);

        $headers = [
            'Товар', 'Артикул', 'Заказ',
            'Кол-во', 'Цена без скидки', 'Скидка %', 'Цена со скидкой', 'Сумма',
            'Валюта',
        ];

        $rows = $shipment->items->map(function ($item) use ($shipment, $userCurrency) {
            $price = (float) ($item->price ?? 0);
            $total = (float) ($item->total ?? 0);
            $qty = (int) $item->quantity;
            $gross = $price * $qty;
            $hasDiscount = $gross > $total + 0.01;
            $effectivePrice = $qty > 0 ? $total / $qty : 0;

            $combinedDiscount = (float) ($item->auto_discount_percent ?? 0)
                + (float) ($item->manual_discount_percent ?? 0);
            $discountPct = $combinedDiscount > 0
                ? $combinedDiscount
                : ($hasDiscount && $gross > 0 ? ($gross - $total) / $gross * 100 : 0);

            $priceConverted = $this->convertAmount($price, $shipment->currency_code, $userCurrency);
            $effectiveConverted = $this->convertAmount($effectivePrice, $shipment->currency_code, $userCurrency);
            $totalConverted = $this->convertAmount($total, $shipment->currency_code, $userCurrency);

            $orderNumber = $item->order?->erp_number ?? $item->order?->number ?? '';

            return [
                $item->product?->name ?? '—',
                $item->product?->sku ?? '',
                $orderNumber,
                $qty,
                round($priceConverted, 2),
                round($discountPct, 2),
                round($effectiveConverted, 2),
                round($totalConverted, 2),
                $userCurrency?->code ?? $shipment->currency_code ?? 'RUB',
            ];
        });

        $shipmentNumber = $shipment->erp_number ?? $shipment->number ?? (string) $shipment->id;
        $filename = "shipment-{$shipmentNumber}-items";

        return $exporter->stream($filename, $headers, $rows, "Отгрузка {$shipmentNumber}");
    }
}
