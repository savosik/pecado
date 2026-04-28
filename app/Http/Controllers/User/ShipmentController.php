<?php

namespace App\Http\Controllers\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\CurrencyService;
use App\Services\SimpleXlsxExporter;
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

        $query = Shipment::query()
            ->where('user_id', $user->id)
            ->with(['company', 'items.product']);

        // Расширенный поиск (см. docs/cabinet-search-scenarios.md §4, C-4.1 … C-4.5).
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $search);
            $queryType = QueryRouter::classify($search);

            $query->where(function ($q) use ($search, $normalized, $queryType) {
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

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowed = ['id', 'date', 'total_amount', 'status'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $shipments = $query->paginate($perPage)->withQueryString();

        // Получить текущую валюту пользователя
        $currency = $this->getUserCurrency($request);

        // Трансформация данных
        $shipments->getCollection()->transform(function ($shipment) use ($currency) {
            $totalConverted = $this->convertAmount((float) $shipment->total_amount, $shipment->currency_code, $currency);

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
                'items_count' => $shipment->items->count(),
                'company' => $shipment->company ? [
                    'id' => $shipment->company->id,
                    'name' => $shipment->company->name,
                ] : null,
            ];
        });

        // Возвращаем выбранные статусы как массив — единый формат для UI multi-select.
        $selectedStatuses = is_array($statusInput)
            ? array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''))
            : ($statusInput ? [(string) $statusInput] : []);

        return Inertia::render('User/Cabinet/Shipments/Index', [
            'shipments' => $shipments,
            'filters' => [
                'search' => $search,
                'status' => $selectedStatuses,
                'order_uuid' => $orderUuid ?: null,
                'brand_ids' => $brandIds,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_from' => $amountFrom,
                'amount_to' => $amountTo,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
            'statuses' => array_map(
                fn ($k, $v) => ['value' => $k, 'label' => $v],
                array_keys(self::STATUS_LABELS),
                self::STATUS_LABELS
            ),
        ]);
    }

    /**
     * Просмотр отгрузки.
     * GET /cabinet/shipments/{shipment}
     */
    public function show(Request $request, Shipment $shipment): InertiaResponse
    {
        $user = $request->user();

        abort_unless($shipment->user_id === $user->id, 403);

        $shipment->load(['company', 'items.product', 'items.product.brand']);

        $currency = $this->getUserCurrency($request);

        $orderUuids = $shipment->items()
            ->whereNotNull('order_uuid')
            ->pluck('order_uuid')
            ->unique()
            ->values();

        $relatedOrders = $orderUuids->isNotEmpty()
            ? Order::withoutGlobalScopes()
                ->whereIn('uuid', $orderUuids)
                ->with(['company'])
                ->withCount(['items', 'shipments'])
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

        return Inertia::render('User/Cabinet/Shipments/Show', [
            'shipment' => [
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
                'company' => $shipment->company ? [
                    'id' => $shipment->company->id,
                    'name' => $shipment->company->name,
                    'legal_name' => $shipment->company->legal_name,
                    'tax_id' => $shipment->company->tax_id,
                ] : null,
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
                    'status_label' => match ($order->status) {
                        OrderStatus::PENDING => 'Ожидает',
                        OrderStatus::CONFIRMED => 'Подтверждён',
                        OrderStatus::READY_TO_SHIP => 'К отгрузке',
                        OrderStatus::CLOSED => 'Закрыт',
                        OrderStatus::DELETED => 'Удалён',
                        default => 'Неизвестно',
                    },
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
            'overdue_detail' => $overdueDetail ? [
                'shipment_uuid' => $overdueDetail->shipment_uuid,
                'amount' => $overdueDetail->amount,
                'due_date' => $overdueDetail->due_date?->format('Y-m-d'),
            ] : null,
        ]);
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
