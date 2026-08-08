<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\Order\OrderChangeAggregator;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use App\Support\Search\EmptyResultSuggestion;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\MatchSourceResolver;
use App\Support\Search\QueryRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService,
        protected OrderChangeAggregator $changeAggregator,
    ) {}

    /**
     * Список заказов текущего пользователя.
     * GET /cabinet/orders
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);
        $search = $context['search'];
        $perPage = $context['per_page'];

        $orders = $query->paginate($perPage)->withQueryString();

        // Валюта пользователя для конвертации
        $currency = $this->getUserCurrency($request);

        // Изменения товарного состава (added/removed) по всем заказам страницы —
        // считаем заранее одним проходом, чтобы разрешить slug товаров без N+1.
        $compositionByOrder = $this->changeAggregator->groupedByOrder($orders->getCollection());

        // Трансформация данных
        $orders->getCollection()->transform(function ($order) use ($currency, $search, $compositionByOrder) {
            $totalConverted = $this->convertAmount((float) $order->total_amount, $order->currency_code, $currency);
            $originalTotalAmount = (float) ($order->original_total_amount ?? 0);
            $originalTotalConverted = $this->convertAmount($originalTotalAmount, $order->currency_code, $currency);

            $match = MatchSourceResolver::resolve(
                $order,
                $search,
                directFields: [
                    ['field' => 'number', 'source' => 'number'],
                    ['field' => 'erp_number', 'source' => 'number'],
                    ['field' => 'uuid', 'source' => 'number'],
                    ['field' => 'comment', 'source' => 'comment'],
                ],
                relationFields: [
                    ['relation' => 'company', 'field' => 'name', 'source' => 'company'],
                    ['relation' => 'company', 'field' => 'tax_id', 'source' => 'company'],
                ],
                itemFields: [
                    // У OrderItem snapshot имени товара хранится в legacy-колонке `name`,
                    // а не `product_name_snapshot` (как у Return/Shipment items, PR 4.1).
                    ['relation' => 'items', 'field' => 'name', 'source' => 'composition'],
                    ['relation' => 'items', 'field' => 'brand_name_snapshot', 'source' => 'composition'],
                ],
            );

            return [
                'id' => $order->id,
                'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'type' => $order->type?->value,
                'delivery_method' => $order->delivery_method?->value ?? 'delivery',
                'delivery_method_label' => $order->delivery_method?->label() ?? 'Доставка',
                'total_amount' => $order->total_amount,
                'total_converted' => $totalConverted,
                'original_total_amount' => $originalTotalAmount,
                'original_total_converted' => $originalTotalConverted,
                'currency_code' => $order->currency_code,
                'erp_created_at' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
                'erp_updated_at' => ($order->erp_updated_at ?? $order->updated_at)?->format('d.m.Y H:i'),
                'is_synced_with_erp' => $order->erp_created_at !== null,
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                ] : null,
                'items_count' => $order->items_count,
                'shipments_count' => $order->shipments_count,
                // Документы одного оформления связаны общим checkout_uuid: чекаут
                // расщепляет корзину по типам и создаёт до пяти заказов.
                // Именно uuid, а не cart_id: корзина живёт долго и переиспользуется
                'cart_id' => $order->cart_id,
                'checkout_uuid' => $order->checkout_uuid,
                'placed_at' => $order->created_at?->format('d.m.Y H:i'),
                'match_source' => $match['source'],
                'match_snippet' => $match['snippet'],
                'composition_changes' => $compositionByOrder[$order->id] ?? null,
            ];
        });

        $companies = $user->companies()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name]);

        $statusCounts = $this->statusCounts($request, $user);

        $suggestion = $orders->total() === 0
            ? EmptyResultSuggestion::build($search, $this->activeFiltersForSuggestion($context, $companies))
            : null;

        return Inertia::render('User/Cabinet/Orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $context['search'],
                'status' => $context['selected_statuses'],
                'type' => $context['type'] ?? '',
                'company_id' => $context['company_id'] ? (string) $context['company_id'] : '',
                'brand_ids' => $context['brand_ids'],
                'product_id' => $context['product_id'] ?: null,
                'date_from' => $context['date_from'],
                'date_to' => $context['date_to'],
                'amount_from' => $context['amount_from'],
                'amount_to' => $context['amount_to'],
                'items_count_from' => $context['items_count_from'] !== null && $context['items_count_from'] !== '' ? (int) $context['items_count_from'] : null,
                'items_count_to' => $context['items_count_to'] !== null && $context['items_count_to'] !== '' ? (int) $context['items_count_to'] : null,
                'sort_by' => $context['sort_by'],
                'sort_order' => $context['sort_order'],
                'per_page' => $perPage,
            ],
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
                'count' => $statusCounts[$case->value] ?? 0,
            ]),
            'statusTotal' => array_sum($statusCounts),
            'types' => OrderType::options(),
            'companies' => $companies,
            'presetsEnabled' => (bool) config('search-cabinet.presets'),
            'exportEnabled' => (bool) config('search-cabinet.export'),
            'suggestion' => $suggestion,
        ]);
    }

    /**
     * Количество заказов по каждому статусу — для быстрых фильтров над списком.
     *
     * Считается по тем же условиям, что и выдача, но **без** фильтра по статусу:
     * иначе выбор одного статуса обнулил бы счётчики всех остальных и по ним
     * нельзя было бы кликнуть.
     *
     * @return array<string, int>
     */
    private function statusCounts(Request $request, User $user): array
    {
        [$query] = $this->buildIndexQuery($request, $user, applyStatusFilter: false);

        // select() сбрасывает колонки и их bindings, снимая подзапросы
        // withCount/withShipmentsCount; reorder() убирает сортировку,
        // недопустимую при GROUP BY в режиме ONLY_FULL_GROUP_BY.
        return $query
            ->reorder()
            ->select('orders.status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('orders.status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Человеческие лейблы активных фильтров для EmptyResultSuggestion.
     * Возвращает пустой массив, когда фильтр не задан, чтобы не подсказывать
     * сбросить то, что и так не активно.
     *
     * @param  array<string, mixed>  $context
     * @param  \Illuminate\Support\Collection  $companies
     * @return array<string, string>
     */
    private function activeFiltersForSuggestion(array $context, $companies): array
    {
        $labels = [];
        if (! empty($context['selected_statuses'])) {
            $labels['Статус'] = implode(', ', $context['selected_statuses']);
        }
        if (! empty($context['type'])) {
            $labels['Тип'] = (string) $context['type'];
        }
        if (! empty($context['company_id'])) {
            $companyName = $companies
                ->firstWhere('value', (string) $context['company_id'])['label'] ?? null;
            $labels['Контрагент'] = $companyName ?? '#'.$context['company_id'];
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
        if ($context['items_count_from'] !== null && $context['items_count_from'] !== ''
            || $context['items_count_to'] !== null && $context['items_count_to'] !== '') {
            $labels['Кол-во позиций'] = trim(($context['items_count_from'] ?? '').'…'.($context['items_count_to'] ?? ''));
        }
        if (! empty($context['product_id'])) {
            $labels['Конкретный товар'] = '#'.$context['product_id'];
        }

        return $labels;
    }

    /**
     * Экспорт текущей выдачи (тех же фильтров, что и `index`) в CSV/XLSX.
     * GET /cabinet/orders/export?format=csv|xlsx
     * За флагом `search-cabinet.export` (PR 5.2).
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        $user = $request->user();
        [$query] = $this->buildIndexQuery($request, $user);
        $currency = $this->getUserCurrency($request);

        $headers = [
            'Номер', 'Тип', 'Статус', 'Дата (ERP)',
            'Контрагент', 'Позиций', 'Отгрузок',
            'Сумма', 'Валюта', 'Сумма в валюте кабинета',
        ];

        $rows = (function () use ($query, $currency) {
            foreach ($query->cursor() as $order) {
                $totalConverted = $this->convertAmount((float) $order->total_amount, $order->currency_code, $currency);
                yield [
                    $order->erp_number ?? $order->number ?? ('#'.$order->id),
                    $order->type?->label() ?? 'Заказ',
                    $this->getStatusLabel($order->status),
                    ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
                    $order->company?->name ?? '',
                    (int) $order->items_count,
                    (int) $order->shipments_count,
                    round((float) $order->total_amount, 2),
                    $order->currency_code ?? 'RUB',
                    round((float) $totalConverted, 2),
                ];
            }
        })();

        $filename = 'orders-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Заказы');
    }

    /**
     * Конструктор query для списка заказов: поиск + фильтры + сортировка.
     * Используется и в `index` (с пагинацией + transform), и в `export`
     * (через cursor без пагинации). Контракт сохраняется идентичным.
     *
     * @param  bool  $applyStatusFilter  false — не применять фильтр по статусу
     *                                   (нужно для подсчёта заказов по статусам,
     *                                   см. `statusCounts`); в `selected_statuses`
     *                                   выбранные статусы возвращаются в любом случае.
     */
    private function buildIndexQuery(Request $request, User $user, bool $applyStatusFilter = true): array
    {
        $search = trim((string) $request->input('search', ''));

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['company'])
            ->when($search !== '', fn ($q) => $q->with([
                'items:id,order_id,name,brand_name_snapshot',
            ]))
            ->withCount(['items'])
            ->withShipmentsCount()
            ->addSelect([
                'original_total_amount' => OrderItem::selectRaw('COALESCE(SUM(base_price * quantity), 0)')
                    ->whereColumn('order_id', 'orders.id'),
            ]);

        if ($search !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $search);
            $queryType = QueryRouter::classify($search);

            $fuzzyOrderIds = FuzzyDocumentMatcher::isApplicable($search, $queryType)
                ? FuzzyDocumentMatcher::findDocumentIds(
                    $search,
                    OrderItem::class,
                    'order_id',
                    'order',
                    $user->id,
                )
                : [];

            $query->where(function ($q) use ($search, $normalized, $queryType, $user, $fuzzyOrderIds) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }

                if ($normalized !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                    $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }

                $q->orWhere('comment', 'like', "%{$search}%");

                $q->orWhereHas('items.product', function ($p) use ($search) {
                    $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });

                $q->orWhereHas('items.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

                if ($queryType === QueryRouter::TYPE_BARCODE) {
                    $q->orWhereHas('items.product.barcodes', fn ($b) => $b->where('barcode', $search));
                }

                $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                    ->where('name', 'like', "%{$search}%"));

                if ($queryType === QueryRouter::TYPE_TAX_ID) {
                    $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                        ->where('tax_id', $search));
                } elseif (ctype_digit($search) && strlen($search) >= 4) {
                    $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                        ->where('tax_id', 'like', "{$search}%"));
                }

                if (! empty($fuzzyOrderIds)) {
                    $q->orWhereIn('id', $fuzzyOrderIds);
                }
            });
        }

        $type = $request->input('type');
        if ($type) {
            $query->where('type', $type);
        }

        $statusInput = $request->input('status');
        if ($applyStatusFilter) {
            if (is_array($statusInput)) {
                $statuses = array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''));
                if (count($statuses) > 0) {
                    $query->whereIn('status', $statuses);
                }
            } elseif ($statusInput) {
                $query->where('status', $statusInput);
            }
        }

        $companyId = $request->input('company_id');
        if ($companyId) {
            $query->where('company_id', $companyId)
                ->whereHas('company', fn ($q) => $q->where('user_id', $user->id));
        }

        $brandIds = array_values(array_filter(
            array_map('intval', (array) $request->input('brand_ids', [])),
            fn ($id) => $id > 0,
        ));
        if (count($brandIds) > 0) {
            $query->whereHas('items.product', fn ($p) => $p->whereIn('brand_id', $brandIds));
        }

        $productId = (int) $request->input('product_id', 0);
        if ($productId) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $productId));
        }

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $amountFrom = $request->input('amount_from');
        $amountTo = $request->input('amount_to');
        if ($amountFrom) {
            $query->where('total_amount', '>=', $amountFrom);
        }
        if ($amountTo) {
            $query->where('total_amount', '<=', $amountTo);
        }

        $itemsCountFrom = $request->input('items_count_from');
        $itemsCountTo = $request->input('items_count_to');
        if ($itemsCountFrom !== null && $itemsCountFrom !== '') {
            $query->whereRaw(
                '(SELECT COUNT(*) FROM order_items WHERE order_items.order_id = orders.id) >= ?',
                [(int) $itemsCountFrom],
            );
        }
        if ($itemsCountTo !== null && $itemsCountTo !== '') {
            $query->whereRaw(
                '(SELECT COUNT(*) FROM order_items WHERE order_items.order_id = orders.id) <= ?',
                [(int) $itemsCountTo],
            );
        }

        $sortBy = $request->input('sort_by', 'erp_created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSortFields = ['id', 'total_amount', 'status', 'erp_created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            if ($sortBy === 'erp_created_at') {
                $direction = $sortOrder === 'asc' ? 'asc' : 'desc';
                $query->orderByRaw("COALESCE(erp_created_at, created_at) {$direction}");
                // Документы одного оформления создаются в одну секунду, поэтому
                // без вторичной сортировки они перемешивались бы. checkout_uuid
                // держит их вместе, id — в порядке сборки (заказ → предзаказ →
                // уценка → промо → образцы), как их и создаёт OrderAssembler
                $query->orderByRaw("checkout_uuid {$direction}")->orderBy('id');
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $selectedStatuses = is_array($statusInput)
            ? array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''))
            : ($statusInput ? [(string) $statusInput] : []);

        return [$query, [
            'search' => $search,
            'type' => $type ?: '',
            'selected_statuses' => $selectedStatuses,
            'company_id' => $companyId,
            'brand_ids' => $brandIds,
            'product_id' => $productId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'items_count_from' => $itemsCountFrom,
            'items_count_to' => $itemsCountTo,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => $perPage,
        ]];
    }

    /**
     * Просмотр заказа.
     * GET /cabinet/orders/{order}
     */
    public function show(Request $request, Order $order): InertiaResponse
    {
        $user = $request->user();

        // Убедиться, что заказ принадлежит текущему пользователю
        abort_unless($order->user_id === $user->id, 403);

        // Загрузить все связи
        $order->load([
            'company:id,name,legal_name,tax_id',
            // is_stub обязателен в выборке: без него sellerPayload() не отличит
            // заглушку и покажет клиенту UUID вместо названия продавца
            'organization:id,name,legal_name,tax_id,is_stub',
            'items.product:id,name,sku,slug',
            'items.product.brand:id,name',
            'items.product.media',
            'statusHistories.user',
            'changeLogs.user',
            'shipments',
        ]);

        return Inertia::render('User/Cabinet/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'type' => $order->type?->value,
                'comment' => $order->comment,
                'manager_comment' => $order->manager_comment,
                'warehouse_comment' => $order->warehouse_comment,
                'total_amount' => $order->total_amount,
                'total_converted' => $this->convertAmount((float) $order->total_amount, $order->currency_code, $this->getUserCurrency($request)),
                // v15.16.0: предоплата по заказу из расшифровки платежей 1С.
                // Накладную не гасит — по ней ещё нет реализации
                'prepaid_amount' => (float) $order->prepaid_amount,
                'prepaid_converted' => $this->convertAmount((float) $order->prepaid_amount, $order->currency_code, $this->getUserCurrency($request)),
                'currency_code' => $order->currency_code,
                'created_at_formatted' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                    'legal_name' => $order->company->legal_name,
                    'tax_id' => $order->company->tax_id,
                ] : null,
                // v15.8.0: продавец — наше юрлицо, на которое 1С провела заказ.
                // Заглушку клиенту не показываем: вместо названия там UUID.
                'seller' => $this->sellerPayload($order),
                'delivery_address' => $order->delivery_address,
                'delivery_method' => $order->delivery_method?->value ?? 'delivery',
                'delivery_method_label' => $order->delivery_method?->label() ?? 'Доставка',
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'base_price' => $item->base_price,
                        'final_price' => $item->final_price,
                        'discount_percent' => $item->discount_percent,
                        'quantity' => $item->quantity,
                        'subtotal' => $item->subtotal,
                        // v15.16.0: строка, отменённая в 1С при недоборе. Показываем
                        // её клиенту, но она не входит в total_amount заказа
                        'cancelled' => (bool) $item->cancelled,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'slug' => $item->product->slug,
                            'image_url' => $item->product->getFirstMediaUrl('main'),
                            'brand' => $item->product->brand ? [
                                'name' => $item->product->brand->name,
                            ] : null,
                        ] : null,
                    ];
                }),

                'shipments' => $order->shipments->map(function ($shipment) use ($request) {
                    $userCurrency = $this->getUserCurrency($request);
                    $totalConverted = $this->convertAmount(
                        (float) $shipment->total_amount,
                        $shipment->currency_code,
                        $userCurrency,
                    );

                    return [
                        'id' => $shipment->id,
                        'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
                        'uuid' => $shipment->uuid,
                        'date' => $shipment->date?->format('Y-m-d'),
                        'status' => $shipment->status,
                        'status_label' => match ($shipment->status) {
                            'completed' => 'Выполнена',
                            'in_progress' => 'В обработке',
                            'new' => 'Новая',
                            'cancelled' => 'Отменена',
                            default => $shipment->status,
                        },
                        'total_amount' => $shipment->total_amount,
                        'total_converted' => $totalConverted,
                        'currency_code' => $shipment->currency_code,
                        'items_count' => $shipment->items()->count(),
                        'updated_at' => $shipment->updated_at?->format('d.m.Y H:i'),
                    ];
                }),
                'status_histories' => $order->statusHistories->map(function ($history) {
                    return [
                        'id' => $history->id,
                        'old_status' => $history->old_status,
                        'new_status' => $history->new_status,
                        'old_status_label' => $history->old_status_label,
                        'new_status_label' => $history->new_status_label,
                        'user_name' => $history->user ? $history->user->name : 'Система',
                        'comment' => $history->comment,
                        'created_at' => $history->created_at->format('d.m.Y H:i'),
                        'created_at_iso' => $history->created_at->toIso8601String(),
                        'created_at_human' => $history->created_at->locale('ru')->diffForHumans(),
                    ];
                }),
                'change_logs' => $order->changeLogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'type' => $log->type,
                        'summary' => $log->summary,
                        'changes' => $log->changes,
                        'source' => $log->source,
                        'user_name' => $log->user?->name,
                        'old_total' => $log->old_total,
                        'new_total' => $log->new_total,
                        'created_at' => $log->created_at->format('d.m.Y H:i'),
                        'created_at_iso' => $log->created_at->toIso8601String(),
                        'created_at_human' => $log->created_at->locale('ru')->diffForHumans(),
                    ];
                }),
            ],
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
        ]);
    }

    /**
     * Получить метку статуса на русском.
     */
    protected function getStatusLabel(?OrderStatus $status): string
    {
        return $status?->label() ?? 'Неизвестно';
    }

    /**
     * Продавец документа — наше юрлицо, на которое 1С провела заказ (v15.8.0).
     *
     * `null` в трёх случаях, и во всех фронт просто не показывает блок:
     * выключен флаг, организация не пришла, либо это заглушка — у заглушки вместо
     * названия лежит UUID, показывать его клиенту нельзя.
     *
     * @return array<string, mixed>|null
     */
    private function sellerPayload(Order $order): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $order->organization;

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
     * Конвертировать сумму из валюты заказа в валюту пользователя.
     */
    private function convertAmount(float $amount, ?string $sourceCurrencyCode, ?Currency $targetCurrency): float
    {
        if (! $targetCurrency || $targetCurrency->is_base) {
            if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
                $src = Currency::where('code', $sourceCurrencyCode)->first();
                if ($src) {
                    return round($amount * (float) $src->exchange_rate, 2);
                }
            }

            return $amount;
        }

        // Сначала в RUB, потом в целевую валюту
        $amountInRub = $amount;
        if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
            $src = Currency::where('code', $sourceCurrencyCode)->first();
            if ($src) {
                $amountInRub = round($amount * (float) $src->exchange_rate, 2);
            }
        }

        return $this->currencyService->convertFromBase($amountInRub, $targetCurrency);
    }

    /**
     * Скачать позиции заказа в Excel (XLSX).
     * GET /cabinet/orders/{order}/items/export
     */
    public function exportItems(Request $request, Order $order, SimpleXlsxExporter $exporter): StreamedResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $order->load(['items.product:id,name,sku']);

        $headers = [
            'Товар', 'Артикул',
            'Кол-во', 'Цена без скидки', 'Скидка %', 'Цена со скидкой', 'Сумма',
            'Валюта', 'Статус строки',
        ];

        $rows = $order->items->map(function ($item) use ($order) {
            $finalPrice = (float) ($item->final_price ?? $item->price ?? 0);
            $rawBase = (float) ($item->base_price ?? 0);
            $rawDiscountPct = (float) ($item->discount_percent ?? 0);
            $hasDiscount = $rawBase > 0 && $finalPrice > 0 && $rawBase > $finalPrice;
            $basePrice = $hasDiscount ? $rawBase : $finalPrice;
            $discountPct = $hasDiscount ? $rawDiscountPct : 0;

            return [
                $item->product?->name ?? $item->name,
                $item->product?->sku ?? '',
                (int) $item->quantity,
                round($basePrice, 2),
                round($discountPct, 2),
                round($finalPrice, 2),
                round((float) $item->subtotal, 2),
                $order->currency_code ?? 'RUB',
                // v15.16.0: строку, отменённую в 1С при недоборе, из выгрузки
                // не выбрасываем — клиент должен видеть, чего не хватило
                $item->cancelled ? 'Отменена — нет в наличии' : '',
            ];
        });

        $orderNumber = $order->erp_number ?? $order->number ?? (string) $order->id;
        $filename = "order-{$orderNumber}-items";

        return $exporter->stream($filename, $headers, $rows, "Заказ {$orderNumber}");
    }

    /**
     * Повторить заказ — добавить его позиции в активную корзину пользователя.
     * POST /cabinet/orders/{order}/repeat
     *
     * Параметр mode:
     *   - 'merge'   — добавить позиции к текущей корзине (аддитивно к количеству);
     *   - 'replace' — очистить корзину, затем добавить позиции.
     *
     * Позиции без привязки к каталогу (product_id пустой — товар удалён из
     * каталога) повторить нельзя, они возвращаются в skipped_count.
     */
    public function repeat(Request $request, Order $order, CartServiceInterface $cartService): JsonResponse
    {
        $user = $request->user();

        abort_unless($order->user_id === $user->id, 403);

        $validated = $request->validate([
            'mode' => 'nullable|in:merge,replace',
        ], [
            'mode.in' => 'Недопустимый режим повтора заказа.',
        ]);
        $mode = $validated['mode'] ?? 'merge';

        $order->load(['items:id,order_id,product_id,name,quantity']);

        // Суммируем количество по товару; отсекаем позиции без привязки к каталогу.
        $orderQuantities = [];
        $skipped = 0;
        foreach ($order->items as $item) {
            $pid = (int) ($item->product_id ?? 0);
            $qty = (int) $item->quantity;
            if ($pid <= 0 || $qty <= 0) {
                $skipped++;

                continue;
            }
            $orderQuantities[$pid] = ($orderQuantities[$pid] ?? 0) + $qty;
        }

        $cart = $cartService->getOrCreateActiveCart($user);

        if ($mode === 'replace') {
            $cart->clear();
        }

        $cartTotals = null;
        if (! empty($orderQuantities)) {
            // Аддитивно: к текущему количеству каждого товара прибавляем количество из заказа.
            $targets = [];
            foreach ($orderQuantities as $pid => $qty) {
                $current = (int) $cart->items()->where('product_id', $pid)->sum('quantity');
                $targets[$pid] = $current + $qty;
            }

            $result = $cartService->setProductsQuantity($user, $cart, $targets);
            $cartTotals = $result['cart_totals'] ?? null;
        }

        $addedCount = count($orderQuantities);

        return response()->json([
            'status' => $addedCount > 0 ? 'success' : 'warning',
            'message' => $addedCount > 0
                ? "Позиции добавлены в корзину: {$addedCount}."
                : 'В заказе нет позиций, доступных для повтора.',
            'mode' => $mode,
            'added_count' => $addedCount,
            'skipped_count' => $skipped,
            'cart_totals' => $cartTotals,
        ], $addedCount > 0 ? 200 : 422);
    }
}
