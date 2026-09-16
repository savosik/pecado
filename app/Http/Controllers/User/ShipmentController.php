<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Currency\CabinetAmountConverter;
use App\Services\Shipment\ClientShipmentPresenter;
use App\Services\Shipment\ClientShipmentQuery;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use App\Support\Cabinet\CabinetFinance;
use App\Support\Search\EmptyResultSuggestion;
use App\Support\Search\MatchSourceResolver;
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
        protected ClientShipmentQuery $shipments,
        protected ClientShipmentPresenter $presenter,
        protected CabinetAmountConverter $amounts,
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
        $currency = $this->amounts->currencyOf($user);
        $financeEnabled = CabinetFinance::enabledFor($user);

        $shipments->getCollection()->transform(function ($shipment) use ($currency, $search, $financeEnabled) {
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

            return $this->presenter->cabinetRow($shipment, $currency, $financeEnabled) + [
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
        $currency = $this->amounts->currencyOf($user);

        // v15.8.0: колонка «Продавец». Пустое значение выводим словами: пустая ячейка
        // в Excel читается как потеря данных, а не как «организации нет».
        $withSeller = (bool) config('erp.organizations.enabled');
        // Колонки оплаты — под тем же флагом, что и экран: выгрузка не должна быть
        // обходным путём к цифрам, которые мы решили клиенту не показывать.
        $financeEnabled = CabinetFinance::enabledFor($user);

        $headers = array_merge(
            ['Номер', 'Статус', 'Дата отгрузки', 'Контрагент'],
            $withSeller ? ['Продавец'] : [],
            ['Позиций', 'Сумма', 'Валюта', 'Сумма в валюте кабинета'],
            $financeEnabled ? ['Статус оплаты', 'Оплачено', 'Остаток к оплате'] : [],
        );

        // with('organization') в buildIndexQuery — иначе колонка даст N+1 на выгрузке
        $rows = (function () use ($query, $currency, $withSeller, $financeEnabled) {
            foreach ($query->cursor() as $shipment) {
                $totalConverted = $this->amounts->convert((float) $shipment->total_amount, $shipment->currency_code, $currency);
                yield array_merge(
                    [
                        $this->presenter->number($shipment),
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

    /**
     * Выборка списка: разбор запроса здесь, сами фильтры — в {@see ClientShipmentQuery},
     * общем с клиентским API.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder<Shipment>, 1: array<string, mixed>}
     */
    private function buildIndexQuery(Request $request, User $user): array
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'status' => $request->input('status'),
            'order_uuid' => $request->input('order_uuid'),
            'brand_ids' => $request->input('brand_ids', []),
            'company_id' => $request->integer('company_id') ?: null,
            'payment_status' => $request->input('payment_status', []),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'amount_from' => $request->input('amount_from'),
            'amount_to' => $request->input('amount_to'),
            'sort_by' => $request->input('sort_by', 'id'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $query = $this->shipments
            ->builder($user, $filters, CabinetFinance::enabledFor($user))
            ->with(['company', 'organization', 'items.product']);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        return [$query, [
            'search' => $filters['search'],
            'selected_statuses' => ClientShipmentQuery::values($filters['status']),
            'payment_statuses' => ClientShipmentQuery::paymentStatuses($filters['payment_status']),
            'company_id' => $filters['company_id'],
            'order_uuid' => $filters['order_uuid'],
            'brand_ids' => array_values(array_filter(array_map('intval', (array) $filters['brand_ids']), fn ($id) => $id > 0)),
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'amount_from' => $filters['amount_from'],
            'amount_to' => $filters['amount_to'],
            'sort_by' => $filters['sort_by'],
            'sort_order' => $filters['sort_order'],
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
        // Прямая ссылка на реализацию «Рекламы» — как на несуществующую.
        abort_if($shipment->isFromInternalOrganization(), 404);

        // is_stub обязателен в выборке: без него seller() покажет клиенту
        // UUID вместо названия продавца
        $shipment->load([
            'company',
            'organization:id,name,legal_name,tax_id,is_stub',
            'items.product',
            'items.product.brand',
        ]);

        $currency = $this->amounts->currencyOf($user);
        $financeEnabled = CabinetFinance::enabledFor($user);

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

        return Inertia::render('User/Cabinet/Shipments/Show', [
            'shipment' => $this->presenter->cabinetCard($shipment, $currency, $financeEnabled, $relatedOrders->keyBy('uuid')),
            'related_orders' => $relatedOrders->map(fn ($order) => $this->presenter->cabinetRelatedOrder($order, $currency)),
            // Построчная просрочка из balance.updated снята (fin-11): срок
            // и остаток видны в графике оплаты, он приходит из регистра.
            'overdue_detail' => null,
        ]);
    }

    /**
     * Скачать состав отгрузки в Excel (XLSX).
     * GET /cabinet/shipments/{shipment}/items/export
     */
    public function exportItems(Request $request, Shipment $shipment, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $user = $request->user();
        abort_unless($shipment->user_id === $user->id, 403);
        // Прямая ссылка на реализацию «Рекламы» — как на несуществующую.
        abort_if($shipment->isFromInternalOrganization(), 404);

        $shipment->load(['items.product:id,name,sku', 'items.order:id,uuid,number,erp_number']);

        $userCurrency = $this->amounts->currencyOf($user);

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

            $priceConverted = $this->amounts->convert($price, $shipment->currency_code, $userCurrency);
            $effectiveConverted = $this->amounts->convert($effectivePrice, $shipment->currency_code, $userCurrency);
            $totalConverted = $this->amounts->convert($total, $shipment->currency_code, $userCurrency);

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
