<?php

namespace App\Http\Controllers\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CurrencyService;
use App\Services\SimpleXlsxExporter;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\MatchSourceResolver;
use App\Support\Search\QueryRouter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * Список заказов текущего пользователя.
     * GET /cabinet/orders
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $search = trim((string) $request->input('search', ''));

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['company'])
            ->when($search !== '', fn ($q) => $q->with([
                'items:id,order_id,name,brand_name_snapshot',
            ]))
            ->withCount(['items', 'shipments'])
            ->addSelect([
                'original_total_amount' => OrderItem::selectRaw('COALESCE(SUM(base_price * quantity), 0)')
                    ->whereColumn('order_id', 'orders.id'),
            ]);

        // Расширенный поиск (см. docs/cabinet-search-scenarios.md §1, C-1.1 … C-1.10).
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
                // Базовое: UUID, номер, ERP-номер, числовой ID (C-1.2 + текущее поведение).
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }

                // Нормализованная форма: 29УТ-003413 ≡ 29УТ003413 (C-1.1). Применяется
                // всегда — пользователь может ввести запрос как с дефисом, так и без.
                if ($normalized !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                    $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }

                // Комментарий (C-1.10).
                $q->orWhere('comment', 'like', "%{$search}%");

                // Состав заказа: название/SKU/код 1С товара (C-1.3, C-1.5, C-1.7).
                $q->orWhereHas('items.product', function ($p) use ($search) {
                    $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });

                // Бренд в составе (C-1.4).
                $q->orWhereHas('items.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

                // Штрихкод в составе — только для строки, похожей на штрихкод (C-1.6, точное).
                if ($queryType === QueryRouter::TYPE_BARCODE) {
                    $q->orWhereHas('items.product.barcodes', fn ($b) => $b->where('barcode', $search));
                }

                // Контрагент по имени — только в рамках компаний пользователя (C-1.8).
                $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                    ->where('name', 'like', "%{$search}%"));

                // ИНН: точное для 10/12 цифр, префиксное для коротких числовых запросов (C-1.9).
                if ($queryType === QueryRouter::TYPE_TAX_ID) {
                    $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                        ->where('tax_id', $search));
                } elseif (ctype_digit($search) && strlen($search) >= 4) {
                    $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                        ->where('tax_id', 'like', "{$search}%"));
                }

                // Fuzzy через Meilisearch (PR 4.2, флаг CABINET_SEARCH_FUZZY_DOCUMENTS).
                if (! empty($fuzzyOrderIds)) {
                    $q->orWhereIn('id', $fuzzyOrderIds);
                }
            });
        }

        // Фильтрация по типу
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        // Фильтрация по статусу — поддерживает скаляр (старое поведение) и массив (multi-select).
        $statusInput = $request->input('status');
        if (is_array($statusInput)) {
            $statuses = array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''));
            if (count($statuses) > 0) {
                $query->whereIn('status', $statuses);
            }
        } elseif ($statusInput) {
            $query->where('status', $statusInput);
        }

        // Фильтрация по контрагенту (только из компаний текущего пользователя)
        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId)
                ->whereHas('company', fn ($q) => $q->where('user_id', $user->id));
        }

        // Фильтр по бренду в составе заказа — массив brand_ids[] (C-1.4 / roadmap PR 2.1).
        $brandIds = array_values(array_filter(
            array_map('intval', (array) $request->input('brand_ids', [])),
            fn ($id) => $id > 0,
        ));
        if (count($brandIds) > 0) {
            $query->whereHas('items.product', fn ($p) => $p->whereIn('brand_id', $brandIds));
        }

        // Фильтр «когда я это покупал?» — заказы, содержащие конкретный товар (C-1.13).
        if ($productId = (int) $request->input('product_id', 0)) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $productId));
        }

        // Фильтрация по дате создания
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Фильтрация по сумме
        if ($amountFrom = $request->input('amount_from')) {
            $query->where('total_amount', '>=', $amountFrom);
        }
        if ($amountTo = $request->input('amount_to')) {
            $query->where('total_amount', '<=', $amountTo);
        }

        // Фильтр по диапазону количества позиций (C-1.12).
        // Используем подзапрос вместо HAVING — он работает и в MySQL, и в SQLite (тесты),
        // не требует GROUP BY и совместим с пагинацией.
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

        // Сортировка (по умолчанию — по дате создания в ERP, свежие сверху)
        $sortBy = $request->input('sort_by', 'erp_created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'total_amount', 'status', 'erp_created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            // erp_created_at может быть NULL у заказов, ещё не дошедших до ERP — fallback на created_at
            if ($sortBy === 'erp_created_at') {
                $direction = $sortOrder === 'asc' ? 'asc' : 'desc';
                $query->orderByRaw("COALESCE(erp_created_at, created_at) {$direction}");
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $orders = $query->paginate($perPage)->withQueryString();

        // Валюта пользователя для конвертации
        $currency = $this->getUserCurrency($request);

        // Трансформация данных
        $orders->getCollection()->transform(function ($order) use ($currency, $search) {
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
                'match_source' => $match['source'],
                'match_snippet' => $match['snippet'],
            ];
        });

        $companies = $user->companies()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name]);

        // Возвращаем выбранные статусы как массив строк — единый формат для UI multi-select.
        $selectedStatuses = is_array($statusInput)
            ? array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''))
            : ($statusInput ? [(string) $statusInput] : []);

        return Inertia::render('User/Cabinet/Orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $selectedStatuses,
                'type' => $request->input('type', ''),
                'company_id' => $companyId ? (string) $companyId : '',
                'brand_ids' => $brandIds,
                'product_id' => $productId ?: null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_from' => $amountFrom,
                'amount_to' => $amountTo,
                'items_count_from' => $itemsCountFrom !== null && $itemsCountFrom !== '' ? (int) $itemsCountFrom : null,
                'items_count_to' => $itemsCountTo !== null && $itemsCountTo !== '' ? (int) $itemsCountTo : null,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
            'types' => [
                ['value' => 'order',    'label' => 'Заказ со склада'],
                ['value' => 'preorder', 'label' => 'Предзаказ'],
            ],
            'companies' => $companies,
        ]);
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
                'total_amount' => $order->total_amount,
                'total_converted' => $this->convertAmount((float) $order->total_amount, $order->currency_code, $this->getUserCurrency($request)),
                'currency_code' => $order->currency_code,
                'created_at' => $order->created_at?->toISOString(),
                'created_at_formatted' => $order->created_at?->format('d.m.Y H:i'),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                    'legal_name' => $order->company->legal_name,
                    'tax_id' => $order->company->tax_id,
                ] : null,
                'delivery_address' => $order->delivery_address,
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
        return match ($status) {
            OrderStatus::PENDING => 'Ожидает',
            OrderStatus::CONFIRMED => 'Подтверждён',
            OrderStatus::READY_TO_SHIP => 'К отгрузке',
            OrderStatus::CLOSED => 'Закрыт',
            OrderStatus::DELETED => 'Удалён',
            default => 'Неизвестно',
        };
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
            'Валюта',
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
            ];
        });

        $orderNumber = $order->erp_number ?? $order->number ?? (string) $order->id;
        $filename = "order-{$orderNumber}-items";

        return $exporter->stream($filename, $headers, $rows, "Заказ {$orderNumber}");
    }
}
