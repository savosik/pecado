<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\Order\OrderAssembler;
use App\Services\Order\OrderChangeAggregator;
use App\Services\Order\OrderChangeLogger;
use App\Services\Order\OrderDraft;
use App\Services\Order\OrderLine;
use App\Services\Promotion\ClientApiPromotions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientApiController extends Controller
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService,
        protected UserCurrencyResolverInterface $currencyResolver,
        protected OrderChangeAggregator $changeAggregator,
        protected OrderChangeLogger $changeLogger,
        protected OrderAssembler $assembler,
        protected ClientApiPromotions $promotions,
    ) {}

    /**
     * GET /api/client-api/{token}/reserves
     * Заказы в резерве (v16.9.0, режим «Заказы в резерве»).
     *
     * Список удержанных заказов интеграции: состав, суммы и фактический срок
     * `reserved_until` (может быть короче запрошенного — 1С урезает до своего
     * предела удержания). Пока заказ в резерве, товар удержан на складе и не
     * уйдёт другому покупателю; не подтвердите до срока — резерв снимется
     * автоматически.
     *
     * Доступно только участнику режима (`reserve_allowed`); иначе 403
     * с кодом `reserve_unavailable`.
     */
    public function reserves(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        if ($guard = $this->guardReserveMode($user)) {
            return $guard;
        }

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('reserve', true)
            ->with(['items' => fn ($q) => $q->where('cancelled', false), 'items.product:id,sku,slug,name'])
            ->orderBy('reserved_until')
            ->get();

        return response()->json([
            'reserves' => $orders->map(fn (Order $order) => [
                'order_id' => $order->id,
                'number' => $order->erp_number ?? $order->number,
                'uuid' => $order->uuid,
                'total_amount' => (float) $order->total_amount,
                'currency_code' => $order->currency_code,
                'reserved_until' => $order->reserved_until?->toIso8601String(),
                'created_at' => ($order->erp_created_at ?? $order->created_at)?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'item_id' => $item->id,
                    'sku' => $item->product?->sku,
                    'name' => $item->product?->name ?? $item->name,
                    'quantity' => (float) $item->quantity,
                    'price' => (float) ($item->final_price ?? $item->price),
                    'subtotal' => (float) $item->subtotal,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/client-api/{token}/reserves/{order}/confirm
     * Подтвердить резервный заказ — отправить в отгрузку (v16.9.0).
     *
     * После подтверждения заказ уходит в сборку по обычному конвейеру,
     * изменить или отменить его через API больше нельзя.
     */
    public function reserveConfirm(Request $request, string $token, int $order): JsonResponse
    {
        $apiToken = $this->resolveToken($token);

        return $this->reserveAction($apiToken->user, $order, function (Order $target) {
            app(\App\Services\Order\ClientOrderActions::class)
                ->confirmReserve($target, app(\App\Services\Erp\OrderReservePublisher::class));

            return response()->json([
                'message' => 'Заказ отправлен в отгрузку.',
                'order_id' => $target->id,
            ]);
        });
    }

    /**
     * POST /api/client-api/{token}/reserves/{order}/items
     * Изменить состав резервного заказа (v16.9.0).
     *
     * Передаётся ЦЕЛЕВОЙ состав: `items: [{item_id, quantity}]` по остающимся
     * строкам (item_id — из списка /reserves); отсутствующие строки удаляются.
     * V1 — только уменьшение: увеличение отклоняется кодом `increase_forbidden`
     * (нужно больше — создайте отдельный заказ). Пустой состав не принимается
     * (`empty_composition`) — для полного отказа используйте /cancel.
     */
    public function reserveItems(Request $request, string $token, int $order): JsonResponse
    {
        $apiToken = $this->resolveToken($token);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => 'Состав пуст — для отказа от всего заказа используйте /cancel.',
            'items.min' => 'Состав пуст — для отказа от всего заказа используйте /cancel.',
        ]);

        $target = array_map(
            fn (array $row) => ['id' => (int) $row['item_id'], 'quantity' => (int) $row['quantity']],
            $validated['items'],
        );

        return $this->reserveAction($apiToken->user, $order, function (Order $orderModel) use ($target) {
            app(\App\Services\Order\ClientOrderActions::class)->updateReserveItems(
                $orderModel,
                $target,
                app(\App\Services\Erp\OrderReservePublisher::class),
                $this->changeLogger,
            );

            return response()->json([
                'message' => 'Состав обновлён.',
                'order_id' => $orderModel->id,
                'total_amount' => (float) $orderModel->refresh()->total_amount,
            ]);
        });
    }

    /**
     * POST /api/client-api/{token}/reserves/{order}/cancel
     * Отменить заказ (v16.9.0).
     *
     * Работает для заказа в окне резерва и для обычного заказа в ранних
     * статусах (пока 1С не начала сборку). Товар возвращается в свободный
     * остаток; поздние статусы отклоняются кодом `not_cancellable`.
     */
    public function reserveCancel(Request $request, string $token, int $order): JsonResponse
    {
        $apiToken = $this->resolveToken($token);

        return $this->reserveAction($apiToken->user, $order, function (Order $target) {
            app(\App\Services\Order\ClientOrderActions::class)->cancel(
                $target,
                app(\App\Services\Erp\OrderReservePublisher::class),
                'Отменён клиентом через API',
            );

            return response()->json([
                'message' => 'Заказ отменён. Товар возвращён в свободный остаток.',
                'order_id' => $target->id,
            ]);
        });
    }

    /**
     * Общий каркас действий над резервом: гейт режима, поиск заказа владельца,
     * маппинг доменных отказов в машиночитаемый JSON.
     *
     * @param  callable(Order): JsonResponse  $action
     */
    protected function reserveAction(\App\Models\User $user, int $orderId, callable $action): JsonResponse
    {
        if ($guard = $this->guardReserveMode($user)) {
            return $guard;
        }

        $order = Order::withTrashed()
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->first();

        if ($order === null) {
            return response()->json(['error' => 'Заказ не найден.', 'code' => 'order_not_found'], 404);
        }

        try {
            return $action($order);
        } catch (\App\Services\Order\ReserveActionException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    /**
     * Режим «Заказы в резерве» доступен только участнику: глобальный рубильник,
     * флаг 1С и отсутствие точечного отключения на сайте.
     */
    protected function guardReserveMode(\App\Models\User $user): ?JsonResponse
    {
        if (! app(\App\Services\Order\ReservePolicy::class)->availableFor($user)) {
            return response()->json([
                'error' => 'Режим «Заказы в резерве» вам недоступен.',
                'code' => 'reserve_unavailable',
            ], 403);
        }

        return null;
    }

    /**
     * GET /api/client-api/{token}/prices
     * Получить цены пользователя.
     *
     * Цены конвертируются в валюту региона пользователя.
     * Опциональный GET-параметр ?currency=BYN для явного указания валюты.
     */
    public function prices(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        // Определяем целевую валюту: явный параметр или валюта региона
        $currency = null;
        if ($currencyCode = $request->query('currency')) {
            $currency = \App\Models\Currency::where('code', $currencyCode)->first();
        }
        if (! $currency) {
            $currency = $this->currencyResolver->resolve($user);
        }

        $currencyService = app(\App\Services\CurrencyService::class);

        $perPage = min((int) $request->input('per_page', 500), 1000);

        $products = Product::query()
            ->select('id', 'external_id', 'code', 'sku', 'barcode', 'base_price', 'name')
            ->orderBy('id')
            ->paginate($perPage);

        $data = $products->getCollection()->map(function (Product $product) use ($user, $currency, $currencyService) {
            $priceResult = $this->priceService->getPriceResult($product, $user);

            $basePrice = round($priceResult->basePrice, 2);
            $displayPrice = round($priceResult->getDisplayPrice(), 2);

            // Конвертация в целевую валюту
            if ($currency && ! $currency->is_base) {
                $basePrice = $currencyService->convertFromBase($basePrice, $currency);
                $displayPrice = $currencyService->convertFromBase($displayPrice, $currency);
            }

            return [
                'uuid' => $product->external_id,
                'code' => $product->code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'base_price' => $basePrice,
                'price' => $displayPrice,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'currency_code' => $currency?->code ?? 'RUB',
                'currency_symbol' => $currency?->symbol ?? '₽',
            ],
        ]);
    }

    /**
     * GET /api/client-api/{token}/stocks
     * Получить остатки по региону пользователя.
     */
    public function stocks(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $perPage = min((int) $request->input('per_page', 500), 1000);

        $products = Product::query()
            ->select('id', 'external_id', 'code', 'sku', 'barcode', 'name')
            ->orderBy('id')
            ->paginate($perPage);

        $data = $products->getCollection()->map(function (Product $product) use ($user) {
            $stock = $this->stockService->getStock($product, $user);

            return [
                'uuid' => $product->external_id,
                'code' => $product->code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'available' => $stock['available'],
                'preorder' => $stock['preorder'],
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * GET /api/client-api/{token}/order-changes
     * Отслеживание изменений товарного состава заказов клиента.
     *
     * Возвращает движения по позициям заказов. Правки состава (kind=edit)
     * свёрнуты к итогу «было → стало» по товару: added (0→N), removed (N→0),
     * changed (N→M); разнонаправленные движения взаимно свёрнуты, нетто-нулевые
     * опущены. Недостача при приёме заказа по API (kind=api) — отдельные записи
     * «запрошено → принято»: not_accepted (N→0), partial (N→M).
     *
     * Фильтры (query): type=added|removed|changed|not_accepted|partial,
     * date_from / date_to (YYYY-MM-DD, по дате изменения), per_page (до 1000), page.
     */
    public function orderChanges(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $type = $request->query('type');
        $type = in_array($type, ['added', 'removed', 'changed', 'not_accepted', 'partial'], true) ? $type : null;
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereHas('changeLogs', fn ($q) => $q->whereIn('type', ['items_updated', 'api_shortfall']))
            ->get();

        $rows = $this->changeAggregator->flatten($orders);

        $rows = array_values(array_filter($rows, function (array $r) use ($type, $dateFrom, $dateTo) {
            if ($type && $r['type'] !== $type) {
                return false;
            }
            $date = $r['changed_at']?->toDateString();
            if ($dateFrom && (! $date || $date < $dateFrom)) {
                return false;
            }
            if ($dateTo && (! $date || $date > $dateTo)) {
                return false;
            }

            return true;
        }));

        // Новые изменения — первыми.
        usort($rows, fn ($a, $b) => ($b['changed_at']?->getTimestamp() ?? 0) <=> ($a['changed_at']?->getTimestamp() ?? 0));

        $perPage = min((int) $request->input('per_page', 500), 1000);
        $perPage = max($perPage, 1);
        $page = max((int) $request->input('page', 1), 1);
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $data = array_map(fn (array $r) => [
            'order_number' => $r['order_number'],
            'order_id' => $r['order_id'],
            'changed_at' => $r['changed_at']?->toIso8601String(),
            'kind' => $r['kind'], // 'edit' — правка состава, 'api' — недостача при приёме
            'type' => $r['type'],
            'product_uuid' => $r['external_id'],
            'product_name' => $r['product_name'],
            'from' => $r['from'],
            'to' => $r['to'],
        ], $slice);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * GET /api/client-api/{token}/shipments
     * Реализации (отгрузочные документы) клиента.
     *
     * Отдаёт документы, проведённые в 1С по вашему аккаунту: номер, дату,
     * контрагента, сумму и — по запросу — товарный состав. Суммы возвращаются
     * в валюте документа (`currency_code`), без пересчёта: цифры должны
     * сходиться с накладной 1С.
     *
     * Фильтры (query): status (скаляр или массив), date_from / date_to
     * (YYYY-MM-DD, по дате отгрузки), updated_since (дата или дата-время —
     * для инкрементальной синхронизации по полю updated_at), inn (ИНН
     * контрагента), order_uuid (реализации по конкретному заказу), number
     * (часть номера, дефисы и пробелы игнорируются), with_items=1 (добавить
     * товарный состав), page, per_page.
     *
     * Блок оплаты (payment_status, paid_amount, unpaid_amount, payment_due_date)
     * появляется только когда денежные данные открыты клиентам в кабинете.
     */
    public function shipments(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $request->validate([
            // status / payment_status принимаем и скаляром, и массивом —
            // нормализация ниже, здесь только ограничение на тип элементов.
            'status' => 'nullable',
            'status.*' => 'string|max:30',
            'payment_status' => 'nullable',
            'payment_status.*' => 'string|max:30',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'updated_since' => 'nullable|date',
            'inn' => 'nullable|string|max:12',
            'order_uuid' => 'nullable|string|max:64',
            'number' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ], [
            'date_from.date_format' => 'Дата начала должна быть в формате ГГГГ-ММ-ДД',
            'date_to.date_format' => 'Дата окончания должна быть в формате ГГГГ-ММ-ДД',
            'updated_since.date' => 'Параметр updated_since должен быть датой или датой со временем',
            'page.min' => 'Номер страницы не может быть меньше 1',
            'per_page.min' => 'Количество на странице не может быть меньше 1',
        ]);

        $financeEnabled = \App\Support\Cabinet\CabinetFinance::enabledFor($user);
        $withItems = $request->boolean('with_items');

        // Реализации внутренних юрлиц («Реклама») в интеграцию клиента не отдаём —
        // та же граница, что в кабинете.
        $query = Shipment::query()
            ->where('user_id', $user->id)
            ->withoutInternalOrganizations()
            ->with(['company:id,name,legal_name,tax_id'])
            ->withCount('items');

        if ($withItems) {
            $query->with($this->shipmentItemsEagerLoad());
        }

        // Статус: принимаем и скаляр (status=completed), и массив (status[]=…).
        $statuses = array_values(array_filter(
            array_map('strval', (array) $request->input('status', [])),
            static fn (string $v): bool => $v !== '',
        ));
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        // Статус оплаты фильтрует только тогда, когда сами суммы клиенту открыты:
        // иначе фильтр стал бы обходным путём к скрытым цифрам долга.
        if ($financeEnabled) {
            $paymentStatuses = array_values(array_intersect(
                array_map('strval', (array) $request->input('payment_status', [])),
                Shipment::PAYMENT_STATUSES,
            ));
            if ($paymentStatuses !== []) {
                $query->whereIn('payment_status', $paymentStatuses);
            }
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        // Инкрементальная выгрузка: «что изменилось с прошлой синхронизации».
        if ($updatedSince = $request->query('updated_since')) {
            $query->where('updated_at', '>=', \Illuminate\Support\Carbon::parse($updatedSince));
        }

        if ($inn = $request->query('inn')) {
            $query->where(fn ($q) => $q->where('tax_id', $inn)
                ->orWhereHas('company', fn ($c) => $c->where('tax_id', $inn)));
        }

        if ($orderUuid = $request->query('order_uuid')) {
            $query->whereHas('items', fn ($q) => $q->where('order_uuid', $orderUuid));
        }

        // Номер ищем и как есть, и в нормализованном виде: 29УТ-003413 ≡ 29УТ003413.
        if ($number = trim((string) $request->query('number', ''))) {
            $normalized = preg_replace('/[\s\-]+/u', '', $number);
            $query->where(function ($q) use ($number, $normalized) {
                $q->where('number', 'like', "%{$number}%")
                    ->orWhere('erp_number', 'like', "%{$number}%");

                // Нормализуем именно колонку: клиент может прислать номер и с
                // дефисом, и без — искать надо в обеих формах.
                if ($normalized !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"])
                        ->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }
            });
        }

        // Свежие документы первыми; id — тай-брейк, дата хранится без времени.
        $query->orderByDesc('date')->orderByDesc('id');

        // Состав раздувает ответ на порядок, поэтому с ним страница короче.
        $maxPerPage = $withItems ? 100 : 500;
        $perPage = min(max((int) $request->input('per_page', 100), 1), $maxPerPage);

        $shipments = $query->paginate($perPage);

        $data = $shipments->getCollection()
            ->map(fn (Shipment $shipment) => $this->shipmentPayload($shipment, $financeEnabled, $withItems))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $shipments->currentPage(),
                'last_page' => $shipments->lastPage(),
                'per_page' => $shipments->perPage(),
                'total' => $shipments->total(),
            ],
        ]);
    }

    /**
     * GET /api/client-api/{token}/shipments/{shipment}
     * Одна реализация с товарным составом.
     *
     * Идентификатор реализации — её `id` на сайте, `uuid` из 1С либо номер
     * (`erp_number` / `number`). Чужой документ не отдаётся: поиск идёт только
     * по реализациям владельца ключа, иначе 404.
     *
     * Дополнительно к полям списка карточка отдаёт `items` (всегда), `orders`
     * (заказы, по которым собран документ) и — когда денежные данные открыты
     * клиентам — `payment_schedule` (график оплаты из «Правил оплаты» 1С).
     */
    public function shipment(Request $request, string $token, string $shipment): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $financeEnabled = \App\Support\Cabinet\CabinetFinance::enabledFor($user);

        $found = $this->resolveShipment($shipment, $user->id);

        $found->loadCount('items');
        $found->load(array_merge(
            ['company:id,name,legal_name,tax_id'],
            $this->shipmentItemsEagerLoad(),
            [],
        ));

        $payload = $this->shipmentPayload($found, $financeEnabled, withItems: true);

        // Заказы, по которым собрана реализация: 1С может собрать документ
        // из нескольких заказов, и клиенту нужно сопоставление с его номерами.
        $payload['orders'] = $found->getRelatedOrders()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'type' => $order->type?->value,
                'status' => $order->status?->value,
                'status_label' => $order->status?->label(),
            ])->values()->all();

        if ($financeEnabled) {
            $payload['payment_schedule'] = \App\Support\Payments\PaymentSchedulePresenter::forShipment($found);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Реализация владельца ключа по id / uuid / номеру.
     */
    protected function resolveShipment(string $identifier, int $userId): Shipment
    {
        $base = fn () => Shipment::query()->where('user_id', $userId)->withoutInternalOrganizations();

        $shipment = (ctype_digit($identifier) ? $base()->whereKey((int) $identifier)->first() : null)
            ?? $base()->where('uuid', $identifier)->first()
            ?? $base()->where('erp_number', $identifier)->first()
            ?? $base()->where('number', $identifier)->first();

        abort_if(! $shipment, 404, 'Реализация не найдена.');

        return $shipment;
    }

    /**
     * Состав реализации с товарами.
     *
     * HiddenScope снимается намеренно: скрытый на витрине товар всё равно
     * отгружен, и без этого его строка приехала бы клиенту без артикулов.
     *
     * @return array<string, \Closure>
     */
    protected function shipmentItemsEagerLoad(): array
    {
        return [
            'items.product' => fn ($q) => $q->withoutGlobalScopes()
                ->select('id', 'external_id', 'code', 'sku', 'barcode', 'name'),
        ];
    }

    /**
     * Представление реализации для клиентского API.
     *
     * @return array<string, mixed>
     */
    protected function shipmentPayload(Shipment $shipment, bool $financeEnabled, bool $withItems): array
    {
        $payload = [
            'id' => $shipment->id,
            'uuid' => $shipment->uuid,
            'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
            'erp_number' => $shipment->erp_number,
            'date' => $shipment->date?->toDateString(),
            'status' => $shipment->status,
            'status_label' => $shipment->status_label,
            'currency_code' => $shipment->currency_code ?? 'RUB',
            'total_amount' => round((float) $shipment->total_amount, 2),
            'items_count' => (int) ($shipment->items_count ?? $shipment->items->count()),
            // Печатный номер счёта-фактуры: клиент сверяет документ по бумаге.
            'invoice_number' => $shipment->invoice_number_display ?: $shipment->invoice_number,
            'invoice_date' => $shipment->invoice_date?->toDateString(),
            'tax_id' => $shipment->tax_id,
            'company' => $shipment->company ? [
                'id' => $shipment->company->id,
                'name' => $shipment->company->name,
                'legal_name' => $shipment->company->legal_name,
                'inn' => $shipment->company->tax_id,
            ] : null,
            // updated_at — время последнего изменения на сайте, по нему же
            // работает фильтр updated_since; erp_updated_at — время из 1С.
            'updated_at' => $shipment->updated_at?->toIso8601String(),
            'erp_updated_at' => $shipment->erp_updated_at?->toIso8601String(),
        ];

        if ($financeEnabled) {
            $payload += [
                'payment_status' => $shipment->payment_status,
                'payment_status_label' => $shipment->payment_status_label,
                'paid_amount' => round((float) $shipment->paid_amount, 2),
                'unpaid_amount' => $shipment->unpaid_amount,
                'payment_due_date' => $shipment->payment_due_date?->toDateString(),
                'is_payment_overdue' => $shipment->is_payment_overdue,
            ];
        }

        if ($withItems) {
            $payload['items'] = $shipment->items
                ->map(fn (\App\Models\ShipmentItem $item) => [
                    'id' => $item->id,
                    'product' => [
                        // Товар мог быть удалён с сайта — тогда остаются снимки
                        // названия и бренда, сделанные при приёме документа.
                        'uuid' => $item->product?->external_id,
                        'code' => $item->product?->code,
                        'sku' => $item->product?->sku,
                        'barcode' => $item->product?->barcode,
                        'name' => $item->product?->name ?? $item->product_name_snapshot,
                        'brand' => $item->brand_name_snapshot,
                    ],
                    'order_uuid' => $item->order_uuid,
                    'quantity' => (int) $item->quantity,
                    'price' => round((float) $item->price, 2),
                    'auto_discount_percent' => round((float) $item->auto_discount_percent, 2),
                    'manual_discount_percent' => round((float) $item->manual_discount_percent, 2),
                    'subtotal' => round((float) $item->subtotal, 2),
                    'total' => round((float) $item->total, 2),
                    'vat_rate' => $item->vat_rate,
                ])->values()->all();
        }

        return $payload;
    }

    /**
     * POST /api/client-api/{token}/orders
     * Создать заказ (с проверкой остатков и разделением на заказ/предзаказ).
     *
     * ## Расчёт акций (`apply_promotions`)
     *
     * Необязательный параметр `apply_promotions` (boolean, по умолчанию `false`).
     * Без него ответ и созданные заказы не меняются ни на байт — поведение
     * существующих интеграций остаётся прежним, ключ `promotions` в JSON
     * не появляется даже пустым.
     *
     * При `apply_promotions: true` сайт считает акции по **фактически принятым**
     * позициям (а не по запрошенным: часть количеств урезается по остатку)
     * и создаёт для промо-позиций отдельные заказы типа `promo` (подотчётные,
     * выписываются в накладной) и `promo_sample` (рекламные образцы). В ответ
     * добавляется блок `promotions` с `applied` (что начислено, включая
     * `order_id` документа) и `near_miss` (до чего клиенту не хватило).
     *
     * ⚠️ **Промо-позиции с ненулевой ценой создают отдельный заказ, который
     * подлежит оплате.** Интерактивного отказа у API нет: передавая
     * `apply_promotions: true`, клиент соглашается на все начисления акции,
     * включая платные.
     *
     * Награды с выбором варианта (`choice`) в API не выдаются — выбор живёт
     * в корзине сайта. Такие акции попадают в `near_miss` с пояснением.
     *
     * Повторная отправка запроса задваивает заказы, включая промо-заказы:
     * идемпотентности у эндпоинта нет.
     */
    public function orders(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $validated = $request->validate([
            'inn' => 'required|string|max:12',
            'address' => 'nullable|string|max:500',
            'delivery_method' => 'nullable|in:delivery,pickup',
            'comment' => 'nullable|string|max:1000',
            'products' => 'required|array|min:1',
            'products.*.identifier' => 'required|string|max:255',
            'products.*.quantity' => 'required|integer|min:1',
            'apply_promotions' => 'nullable|boolean',
            // v16.9.0 (режим «Заказы в резерве»): создать заказ с удержанием товара.
            // Резервируется только складская часть (type=order); подтверждение,
            // правка и отмена — методы /reserves/*
            'reserve' => 'nullable|boolean',
        ], [
            'inn.required' => 'ИНН обязателен',
            'delivery_method.in' => 'Недопустимый способ доставки. Допустимые значения: delivery, pickup',
            'products.required' => 'Список товаров обязателен',
            'products.min' => 'Список товаров не может быть пустым',
            'products.*.identifier.required' => 'Идентификатор товара обязателен',
            'products.*.quantity.required' => 'Количество обязательно',
            'products.*.quantity.min' => 'Количество должно быть не менее 1',
        ]);

        // Найти компанию по ИНН
        /** @var \App\Models\Company|null $company */
        $company = $user->companies()->where('tax_id', $validated['inn'])->first();
        if (! $company) {
            return response()->json([
                'error' => 'Компания с указанным ИНН не найдена в вашем аккаунте',
                'inn' => $validated['inn'],
            ], 422);
        }

        // v16.9.0: резерв доступен только участнику режима — явный отказ,
        // а не молчаливое игнорирование флага
        $reserve = $request->boolean('reserve');
        if ($reserve && ! app(\App\Services\Order\ReservePolicy::class)->availableFor($user)) {
            return response()->json([
                'error' => 'Режим «Заказы в резерве» вам недоступен.',
                'code' => 'reserve_unavailable',
            ], 403);
        }

        // Резолвим товары и раскладываем на выполнимую часть.
        // Дружественная логика: заказ принимается даже если часть позиций
        // недоступна. Недостающее не блокирует заказ, а попадает в
        // информационный ответ (not_accepted — не попали в заказ вовсе,
        // partial — приняты не в полном объёме). Каждая запись несёт `line`
        // (номер строки запроса) для сопоставления при дублях identifier.
        $instockItems = [];
        $preorderItems = [];
        $notAccepted = [];
        $partial = [];

        foreach ($validated['products'] as $idx => $item) {
            $requestedQty = $item['quantity'];
            $line = $idx + 1; // 1-based номер строки запроса — для сопоставления дублей identifier

            $product = $this->resolveProduct($item['identifier']);
            if (! $product) {
                $notAccepted[] = [
                    'line' => $line,
                    'identifier' => $item['identifier'],
                    'product_id' => null,
                    'slug' => null,
                    'name' => $item['identifier'],
                    'requested' => $requestedQty,
                    'reason' => 'not_found',
                    'message' => 'Товар не найден',
                ];

                continue;
            }

            $stock = $this->stockService->getStock($product, $user);
            $available = $stock['available'];
            $preorder = $stock['preorder'];
            $totalAvailable = $available + $preorder;

            if ($totalAvailable <= 0) {
                $notAccepted[] = [
                    'line' => $line,
                    'identifier' => $item['identifier'],
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'reason' => 'out_of_stock',
                    'message' => 'Нет в наличии',
                ];

                continue;
            }

            // Отгружаем столько, сколько реально доступно; остаток запроса — в shortfall.
            $fulfillQty = min($requestedQty, $totalAvailable);
            $instockQty = min($fulfillQty, $available);
            $preorderQty = $fulfillQty - $instockQty;

            if ($instockQty > 0) {
                $instockItems[] = ['product' => $product, 'quantity' => $instockQty];
            }
            if ($preorderQty > 0) {
                $preorderItems[] = ['product' => $product, 'quantity' => $preorderQty];
            }

            if ($fulfillQty < $requestedQty) {
                $partial[] = [
                    'line' => $line,
                    'identifier' => $item['identifier'],
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'fulfilled' => $fulfillQty,
                    'shortfall' => $requestedQty - $fulfillQty,
                ];
            }
        }

        // Совсем нечего отгружать — заказ не создаём.
        if (empty($instockItems) && empty($preorderItems)) {
            return response()->json([
                'error' => 'Ни одна из позиций недоступна для заказа',
                'not_accepted' => $notAccepted,
            ], 422);
        }

        // Валюта пользователя (как в CheckoutService)
        $currency = $this->currencyResolver->resolve($user);

        // Дополняем комментарий системной пометкой о недоступных/частичных
        // позициях, чтобы менеджер и 1С видели, что клиент запрашивал больше.
        $comment = $validated['comment'] ?? null;
        if ($note = $this->buildFulfillmentNote($notAccepted, $partial)) {
            $comment = $comment !== null && $comment !== '' ? ($comment."\n\n".$note) : $note;
        }

        // Способ доставки (v15.3): delivery по умолчанию; при самовывозе адрес не хранится
        $deliveryMethod = $validated['delivery_method'] ?? DeliveryMethod::DELIVERY->value;

        // Акции считаются по принятым позициям, а не по запрошенным: иначе подарок
        // уедет за товар, которого не отгрузили. По умолчанию расчёта нет —
        // клиент, который не просил подарков, не должен получить лишний заказ
        $applyPromotions = $request->boolean('apply_promotions');

        $promoResult = $applyPromotions
            ? $this->promotions->resolve(array_merge($instockItems, $preorderItems), $user)
            : null;

        $draft = new OrderDraft(
            user: $user,
            company: $company,
            deliveryMethod: DeliveryMethod::from($deliveryMethod),
            groups: [
                OrderType::ORDER->value => $this->linesFromApiItems($instockItems),
                OrderType::PREORDER->value => $this->linesFromApiItems($preorderItems),
                OrderType::PROMO->value => $promoResult?->groups[OrderType::PROMO->value] ?? [],
                OrderType::PROMO_SAMPLE->value => $promoResult?->groups[OrderType::PROMO_SAMPLE->value] ?? [],
            ],
            deliveryAddress: $validated['address'] ?? null,
            comment: $comment,
            currency: $currency,
            // Лист отбора промо-позиций — та же пометка складу, что и в чекауте
            warehouseComments: $promoResult !== null ? $promoResult->warehouseComments : [],
            reserve: $reserve,
            reservedUntil: $reserve
                ? app(\App\Services\Order\ReservePolicy::class)->requestedReservedUntil($user)
                : null,
        );

        // Заказы и запись о недостаче — одной транзакцией. OrderCreated сборщик
        // выпустит после коммита, одинаково с чекаутом
        $createdOrders = DB::transaction(function () use ($draft, $notAccepted, $partial) {
            $orders = $this->assembler->assemble($draft);

            // Логируем недостачу при приёме как структурную запись в общий workflow
            // изменений (недостача видна в «Изменениях заказов», значке и API).
            // Текстовая пометка в комментарии сохраняется отдельно — её видит 1С.
            if (! empty($notAccepted) || ! empty($partial)) {
                $this->changeLogger->logApiShortfall(
                    $orders->first(),
                    array_map(fn (array $u) => [
                        'product_id' => $u['product_id'] ?? null,
                        'slug' => $u['slug'] ?? null,
                        'product_name' => $u['name'] ?? $u['identifier'],
                        'requested' => $u['requested'],
                        'reason' => $u['reason'] ?? null,
                        'message' => $u['message'] ?? null,
                    ], $notAccepted),
                    array_map(fn (array $p) => [
                        'product_id' => $p['product_id'] ?? null,
                        'slug' => $p['slug'] ?? null,
                        'product_name' => $p['name'] ?? $p['identifier'],
                        'requested' => $p['requested'],
                        'fulfilled' => $p['fulfilled'],
                    ], $partial),
                );
            }

            return $orders->all();
        });

        // Формируем ответ
        $responseOrders = array_map(fn (Order $order) => [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'type' => $order->type?->value ?? 'order',
            'delivery_method' => $order->delivery_method?->value ?? DeliveryMethod::DELIVERY->value,
            'total_amount' => round((float) $order->total_amount, 2),
            'items_count' => $order->items()->count(),
            'status' => $order->status?->value ?? OrderStatus::PENDING_APPROVAL->value,
            // v16.9.0: запрошенный срок удержания; фактический (возможно, урезанный
            // 1С) виден в GET /reserves после эха
            ...($order->reserve ? ['reserve' => true, 'reserved_until' => $order->reserved_until?->toIso8601String()] : []),
        ], $createdOrders);

        $response = [
            'orders' => $responseOrders,
            'total_orders' => count($createdOrders),
            'fully_fulfilled' => empty($notAccepted) && empty($partial),
        ];

        if (! empty($notAccepted) || ! empty($partial)) {
            $response['warnings'] = [
                'message' => 'Заказ принят. Часть позиций недоступна или отгружена не в полном объёме.',
                'not_accepted' => $notAccepted,
                'partial' => $partial,
            ];
        }

        // Блок появляется только при apply_promotions=true — даже пустым ключом
        // ответ раздувать нельзя, некоторые клиентские парсеры строгие
        if ($promoResult !== null) {
            $response['promotions'] = $promoResult->toResponse($createdOrders);
        }

        return response()->json($response, 201);
    }

    /**
     * Собрать текстовую пометку о недоступных/частичных позициях для комментария заказа.
     * Возвращает null, если заказ выполнен полностью.
     *
     * @param  array<int, array<string, mixed>>  $notAccepted
     * @param  array<int, array<string, mixed>>  $partial
     */
    protected function buildFulfillmentNote(array $notAccepted, array $partial): ?string
    {
        if (empty($notAccepted) && empty($partial)) {
            return null;
        }

        $lines = ['[API] Заказ принят не в полном объёме:'];

        foreach ($notAccepted as $u) {
            $label = $u['name'] ?? $u['identifier'];
            $lines[] = "— «{$label}» (запрошено {$u['requested']}): {$u['message']}";
        }

        foreach ($partial as $p) {
            $lines[] = "— «{$p['name']}»: запрошено {$p['requested']}, отгружено {$p['fulfilled']}, не хватило {$p['shortfall']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Разрешённые к заказу позиции → строки для сборщика.
     *
     * Цену считает сборщик по прайсу клиента — так же, как в чекауте.
     *
     * @param  array<int, array{product: \App\Models\Product, quantity: int}>  $items
     * @return list<OrderLine>
     */
    private function linesFromApiItems(array $items): array
    {
        return array_map(
            static fn (array $item) => new OrderLine($item['product'], $item['quantity']),
            array_values($items),
        );
    }

    /**
     * Найти и валидировать API-токен.
     */
    protected function resolveToken(string $token): ApiToken
    {
        $apiToken = ApiToken::where('token', $token)
            ->where('is_active', true)
            ->first();

        abort_if(! $apiToken, 404, 'API-ключ не найден или деактивирован.');

        // Обновить last_used_at, не чаще 1 раза в минуту
        if (! $apiToken->last_used_at || $apiToken->last_used_at->diffInMinutes(now()) >= 1) {
            $apiToken->touchLastUsed();
        }

        return $apiToken;
    }

    /**
     * Найти товар по идентификатору (uuid, code, sku, barcode).
     */
    protected function resolveProduct(string $identifier): ?Product
    {
        // UUID формат: 8-4-4-4-12 hex chars
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            $product = Product::where('external_id', $identifier)->first();
            if ($product) {
                return $product;
            }
        }

        // Попробовать по code, sku, barcode — в этом порядке
        return Product::where('code', $identifier)->first()
            ?? Product::where('sku', $identifier)->first()
            ?? Product::where('barcode', $identifier)->first();
    }
}
