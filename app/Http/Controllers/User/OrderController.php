<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Currency\CabinetAmountConverter;
use App\Services\Order\ClientOrderPresenter;
use App\Services\Order\ClientOrderQuery;
use App\Services\Order\OrderChangeAggregator;
use App\Services\Order\OrderRepeater;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use App\Support\Search\EmptyResultSuggestion;
use App\Support\Search\MatchSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(
        protected OrderChangeAggregator $changeAggregator,
        protected ClientOrderQuery $orders,
        protected ClientOrderPresenter $presenter,
        protected CabinetAmountConverter $amounts,
    ) {}

    /**
     * Раздел предзаказов — тот же список, отобранный по типу.
     *
     * Предзаказ вынесен в отдельный пункт меню: у него своя жизнь («когда
     * приедет»), а по статусам он ходит по тем же десяти значениям, что и
     * заказ. Поэтому раздел определяется маршрутом, а не фильтром `type`:
     * иначе один документ попадал бы в оба списка и считался дважды.
     */
    private function isPreorderScope(Request $request): bool
    {
        return $request->routeIs('cabinet.preorders.*');
    }

    /**
     * Список заказов текущего пользователя.
     * GET /cabinet/orders — заказы (всё, кроме предзаказов)
     * GET /cabinet/preorders — предзаказы
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);
        $search = $context['search'];
        $perPage = $context['per_page'];

        $orders = $query->paginate($perPage)->withQueryString();

        // Валюта пользователя для конвертации
        $currency = $this->amounts->currencyOf($user);

        // Изменения товарного состава (added/removed) по всем заказам страницы —
        // считаем заранее одним проходом, чтобы разрешить slug товаров без N+1.
        $compositionByOrder = $this->changeAggregator->groupedByOrder($orders->getCollection());

        // Трансформация данных
        $this->presenter->primeFulfilment($orders->getCollection());

        $orders->getCollection()->transform(function ($order) use ($currency, $search, $compositionByOrder) {
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

            return $this->presenter->cabinetRow($order, $currency) + [
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

        $isPreorders = $this->isPreorderScope($request);

        return Inertia::render('User/Cabinet/Orders/Index', [
            'scope' => $isPreorders ? 'preorders' : 'orders',
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
                'label' => $this->presenter->statusLabel($case),
                'count' => $statusCounts[$case->value] ?? 0,
            ]),
            'statusTotal' => array_sum($statusCounts),
            // В разделе предзаказов фильтр по типу лишён смысла — тип там один;
            // в разделе заказов из списка убран «Предзаказ», он живёт отдельно.
            'types' => $isPreorders
                ? []
                : array_values(array_filter(
                    OrderType::options(),
                    fn (array $option) => $option['value'] !== OrderType::PREORDER->value,
                )),
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
        return $this->orders->statusCounts($user, $this->filtersFrom($request), $this->isPreorderScope($request));
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
        $currency = $this->amounts->currencyOf($user);

        $headers = [
            'Номер', 'Тип', 'Статус', 'Дата (ERP)',
            'Контрагент', 'Позиций', 'Отгрузок',
            'Сумма', 'Валюта', 'Сумма в валюте кабинета',
        ];

        $rows = (function () use ($query, $currency) {
            foreach ($query->cursor() as $order) {
                $totalConverted = $this->amounts->convert((float) $order->total_amount, $order->currency_code, $currency);
                yield [
                    $this->presenter->number($order),
                    $order->type?->label() ?? 'Заказ',
                    $this->presenter->statusLabel($order->status),
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

        $isPreorders = $this->isPreorderScope($request);
        $filename = ($isPreorders ? 'preorders-' : 'orders-').now()->format('Y-m-d-His');
        $sheet = $isPreorders ? 'Предзаказы' : 'Заказы';

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, $sheet);
    }

    /**
     * Конструктор query для списка заказов: поиск + фильтры + сортировка.
     * Используется и в `index` (с пагинацией + transform), и в `export`
     * (через cursor без пагинации). Сама выборка живёт в {@see ClientOrderQuery}
     * — общем с клиентским API; здесь только разбор запроса и контекст фильтров.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder<Order>, 1: array<string, mixed>}
     */
    private function buildIndexQuery(Request $request, User $user): array
    {
        $filters = $this->filtersFrom($request);
        $query = $this->orders->builder($user, $filters, $this->isPreorderScope($request));

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        return [$query, [
            'search' => $filters['search'],
            'type' => $filters['type'] ?: '',
            'selected_statuses' => ClientOrderQuery::statuses($filters['status']),
            'company_id' => $filters['company_id'],
            'brand_ids' => ClientOrderQuery::brandIds($filters['brand_ids']),
            'product_id' => (int) $filters['product_id'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'amount_from' => $filters['amount_from'],
            'amount_to' => $filters['amount_to'],
            'items_count_from' => $filters['items_count_from'],
            'items_count_to' => $filters['items_count_to'],
            'sort_by' => $filters['sort_by'],
            'sort_order' => $filters['sort_order'],
            'per_page' => $perPage,
        ]];
    }

    /**
     * Фильтры списка из запроса — в форме, которую понимает {@see ClientOrderQuery}.
     *
     * @return array<string, mixed>
     */
    private function filtersFrom(Request $request): array
    {
        return [
            'search' => trim((string) $request->input('search', '')),
            'type' => $request->input('type'),
            'status' => $request->input('status'),
            'company_id' => $request->input('company_id'),
            'brand_ids' => $request->input('brand_ids', []),
            'product_id' => $request->input('product_id', 0),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'amount_from' => $request->input('amount_from'),
            'amount_to' => $request->input('amount_to'),
            'items_count_from' => $request->input('items_count_from'),
            'items_count_to' => $request->input('items_count_to'),
            'sort_by' => $request->input('sort_by', 'erp_created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];
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

        $this->presenter->loadCabinetCard($order);

        return Inertia::render('User/Cabinet/Orders/Show', [
            'order' => $this->presenter->cabinetCard($order, $user),
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->presenter->statusLabel($case),
            ]),
        ]);
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
    /**
     * Отмена заказа клиентом (v16.9.0, режим «Заказы в резерве», res-04).
     * POST /cabinet/orders/{order}/cancel
     *
     * Доступна, пока 1С не начала сборку (ранние статусы) либо пока заказ в окне
     * резерва. В 1С уходит order.deleted с reason=client_cancelled (там — отмена
     * строк + «Закрыт», без пометки удаления); локально заказ закрывается сразу,
     * не дожидаясь эха, — клиент видит результат мгновенно.
     */
    public function cancel(
        Request $request,
        Order $order,
        \App\Services\Erp\OrderReservePublisher $publisher,
    ): JsonResponse {
        // Весь контур резервов за глобальным рубильником — до включения владельцами
        // кнопка не рендерится, а прямой запрос получает 404.
        abort_unless((bool) config('order_reserve.enabled'), 404);
        abort_unless($order->user_id === $request->user()->id, 403);

        try {
            // Гонку (статус уехал между рендером и запросом) отбивает сервис
            app(\App\Services\Order\ClientOrderActions::class)
                ->cancel($order, $publisher, 'Отменён клиентом из кабинета');
        } catch (\App\Services\Order\ReserveActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'Заказ отменён. Товар возвращён в свободный остаток.',
        ]);
    }

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

        $result = app(OrderRepeater::class)->repeat($user, $order, $mode);
        $addedCount = $result['added_count'];

        return response()->json([
            'status' => $addedCount > 0 ? 'success' : 'warning',
            'message' => $addedCount > 0
                ? "Позиции добавлены в корзину: {$addedCount}."
                : 'В заказе нет позиций, доступных для повтора.',
            'mode' => $mode,
            'added_count' => $addedCount,
            'skipped_count' => $result['skipped_count'],
            'cart_totals' => $result['cart_totals'],
        ], $addedCount > 0 ? 200 : 422);
    }
}
