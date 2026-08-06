<?php

namespace App\Http\Controllers\Crm;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CrmEntityResolver;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Заказы и реализации внутри CRM: списки и карточки.
 *
 * Раньше из ленты и из списка документов ссылка вела в админку — куда роли
 * `sales-head` и `sales-manager-crm` намеренно не пускают. Менеджер видел
 * ссылку и упирался в 403, а РОП не мог посмотреть состав заказа вообще.
 *
 * Отдельного права нет: документ клиента — часть его карточки, и «вижу клиента,
 * но не вижу его заказы» — состояние, которого быть не должно. Доступ решает
 * тот же скоуп, что и везде, через CrmEntityResolver: чужой документ даёт 404.
 *
 * Читаем только — заказы и реализации принадлежат 1С, редактирование живёт
 * в админке и там же остаётся.
 */
class DocumentController extends CrmController
{
    /**
     * Поля, по которым разрешена сортировка списков.
     *
     * Белый список, а не проверка «есть такая колонка»: значение уходит
     * в orderBy как есть.
     *
     * @var list<string>
     */
    private const SORTS = ['id', 'number', 'erp_number', 'total_amount', 'created_at', 'erp_created_at', 'date'];

    /**
     * Статусы реализаций: у них нет enum-а, 1С шлёт голые строки.
     *
     * @var list<array{value: string, label: string, color: string}>
     */
    private const SHIPMENT_STATUSES = [
        ['value' => 'new', 'label' => 'Новая', 'color' => 'blue'],
        ['value' => 'in_progress', 'label' => 'В обработке', 'color' => 'orange'],
        ['value' => 'completed', 'label' => 'Выполнена', 'color' => 'green'],
        ['value' => 'cancelled', 'label' => 'Отменена', 'color' => 'gray'],
    ];

    public function __construct(private readonly CrmEntityResolver $resolver) {}

    /**
     * Список заказов клиентов актора.
     *
     * Скоуп тот же, что у списка клиентов: менеджер видит документы только своих
     * клиентов, РОП — всего отдела. Реализовано подзапросом по user_id, а не
     * фильтром на фронте — иначе первый же `per_page=100` показал бы чужое.
     */
    public function orders(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);

        $query = Order::query()
            ->whereIn('user_id', (clone $clients))
            ->with(['user:id,name,erp_name,email', 'company:id,name', 'organization:id,name,is_stub', 'warehouse:id,name'])
            ->withCount('items');

        if ($search = $this->search($request)) {
            $like = '%'.$search.'%';

            $query->where(function ($inner) use ($search, $like): void {
                $inner->where('number', 'like', $like)
                    ->orWhere('erp_number', 'like', $like)
                    ->orWhere('id', $search)
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like))
                    ->orWhereHas('items', fn ($item) => $item->where('name', 'like', $like));
            });
        }

        $statuses = array_map(fn (OrderStatus $case): string => $case->value, OrderStatus::cases());

        $this->applyCommonFilters($query, $request, 'erp_created_at', $statuses);

        $sortBy = in_array($request->input('sort_by'), self::SORTS, true)
            ? (string) $request->input('sort_by')
            : 'erp_created_at';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $orders = $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString();

        $orders->getCollection()->transform(fn (Order $order): array => [
            'id' => (int) $order->getKey(),
            'number' => $order->number,
            'erp_number' => $order->erp_number,
            'status_label' => $order->status->label(),
            'status_color' => $order->status->color(),
            'date_label' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
            'total_label' => $this->money((float) $order->total_amount, $order->currency_code),
            'items_count' => (int) ($order->items_count ?? 0),
            'client' => $order->user === null ? null : [
                'id' => (int) $order->user->getKey(),
                'name' => $order->user->display_name,
                'url' => route('crm.clients.show', $order->user->getKey()),
            ],
            'organization' => $order->organization === null ? null : [
                'name' => $order->organization->getAttribute('name'),
                'is_stub' => (bool) $order->organization->getAttribute('is_stub'),
            ],
            'warehouse' => $order->warehouse?->getAttribute('name'),
            'url' => route('crm.orders.show', $order->getKey()),
        ]);

        return Inertia::render('Crm/Pages/Documents/Orders', array_merge(
            ['orders' => $orders],
            $this->listOptions($request, 'orders', $clients, $sortBy, $sortOrder, $perPage, $search),
            [
                'statuses' => array_map(
                    fn (OrderStatus $case): array => [
                        'value' => $case->value,
                        'label' => $case->label(),
                        'color' => $case->color(),
                    ],
                    OrderStatus::cases(),
                ),
            ],
        ));
    }

    /**
     * Список реализаций клиентов актора.
     */
    public function shipments(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);

        $query = Shipment::query()
            ->whereIn('user_id', (clone $clients))
            ->with(['user:id,name,erp_name,email', 'company:id,name', 'organization:id,name,is_stub', 'warehouse:id,name'])
            ->withCount('items');

        if ($search = $this->search($request)) {
            $like = '%'.$search.'%';

            $query->where(function ($inner) use ($search, $like): void {
                $inner->where('number', 'like', $like)
                    ->orWhere('erp_number', 'like', $like)
                    ->orWhere('id', $search)
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like))
                    ->orWhereHas('items', fn ($item) => $item->where('product_name_snapshot', 'like', $like));
            });
        }

        $this->applyCommonFilters(
            $query,
            $request,
            'erp_created_at',
            array_column(self::SHIPMENT_STATUSES, 'value'),
        );

        $sortBy = in_array($request->input('sort_by'), self::SORTS, true)
            ? (string) $request->input('sort_by')
            : 'erp_created_at';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $shipments = $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString();

        $shipments->getCollection()->transform(fn (Shipment $shipment): array => [
            'id' => (int) $shipment->getKey(),
            'number' => $shipment->number,
            'erp_number' => $shipment->erp_number,
            'status_label' => $shipment->status_label,
            'status_color' => $shipment->status === 'completed' ? 'green' : 'blue',
            'date_label' => ($shipment->erp_created_at ?? $shipment->date)?->format('d.m.Y H:i'),
            'total_label' => $this->money((float) $shipment->total_amount, $shipment->currency_code),
            'items_count' => (int) ($shipment->items_count ?? 0),
            'client' => $shipment->user === null ? null : [
                'id' => (int) $shipment->user->getKey(),
                'name' => $shipment->user->display_name,
                'url' => route('crm.clients.show', $shipment->user->getKey()),
            ],
            'organization' => $shipment->organization === null ? null : [
                'name' => $shipment->organization->getAttribute('name'),
                'is_stub' => (bool) $shipment->organization->getAttribute('is_stub'),
            ],
            'warehouse' => $shipment->warehouse?->getAttribute('name'),
            'url' => route('crm.shipments.show', $shipment->getKey()),
        ]);

        return Inertia::render('Crm/Pages/Documents/Shipments', array_merge(
            ['shipments' => $shipments],
            $this->listOptions($request, 'shipments', $clients, $sortBy, $sortOrder, $perPage, $search),
            ['statuses' => self::SHIPMENT_STATUSES],
        ));
    }

    /**
     * Фильтры, одинаковые у обоих списков: статусы, партнёры, контрагенты,
     * организации, склады, товар в позициях, даты, суммы.
     *
     * Менеджер сюда не попал намеренно: он сужает набор клиентов, а не
     * документов, и применяется раньше — в visibleClients(). Партнёр же —
     * обычная колонка user_id, и чужой id безопасен: скоуп клиентов уже сузил
     * выборку, пересечение с ним ничего не открывает.
     *
     * Шаблонный параметр, а не объединение Order|Shipment: дженерик Builder
     * инвариантен, и объединение не приняло бы ни один из двух конкретных типов.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  list<string>  $allowedStatuses
     */
    private function applyCommonFilters(Builder $query, Request $request, string $dateColumn, array $allowedStatuses): void
    {
        $statuses = array_values(array_intersect($this->values($request, 'statuses', 'status'), $allowedStatuses));

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $this->applyIdFilter($query, 'user_id', $this->values($request, 'partner_ids'));
        $this->applyIdFilter($query, 'company_id', $this->values($request, 'company_ids'));
        // 'none' — документы без организации: их много в переходный период,
        // и именно их бывает нужно отобрать.
        $this->applyIdFilter($query, 'organization_id', $this->values($request, 'organization_ids', 'organization_id'));
        $this->applyIdFilter($query, 'warehouse_id', $this->values($request, 'warehouse_ids', 'warehouse_id'));

        // Товар — «в каком документе он есть»: whereHas по позициям, а не join,
        // иначе документ с двумя выбранными товарами задвоился бы в списке.
        $productIds = $this->ids($request, 'product_ids');

        if ($productIds !== []) {
            $query->whereHas('items', fn (Builder $item) => $item->whereIn('product_id', $productIds));
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate($dateColumn, '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate($dateColumn, '<=', $dateTo);
        }

        if ($amountFrom = $request->input('amount_from')) {
            $query->where('total_amount', '>=', (float) $amountFrom);
        }

        if ($amountTo = $request->input('amount_to')) {
            $query->where('total_amount', '<=', (float) $amountTo);
        }
    }

    /**
     * Общие пропсы списков: справочники фильтров и снимок текущего отбора.
     *
     * @param  'orders'|'shipments'  $table
     * @param  Builder<User>  $clients  скоуп клиентов актора (уже с фильтром по менеджеру)
     * @return array<string, mixed>
     */
    private function listOptions(Request $request, string $table, Builder $clients, string $sortBy, string $sortOrder, int $perPage, ?string $search): array
    {
        $organizationsEnabled = (bool) config('erp.organizations.enabled');
        $seesAll = $this->seesAllClients($request);
        $productIds = $this->ids($request, 'product_ids');

        return [
            'organizations' => $organizationsEnabled
                ? Organization::query()->ordered()->where('is_stub', false)->get(['id', 'name'])
                : [],
            'organizationsEnabled' => $organizationsEnabled,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'partners' => $this->partnerOptions($table, $clients),
            'companies' => $this->companyOptions($table, $clients),
            // Менеджер — только РОПу: у рядового менеджера в скоупе и так
            // только свои клиенты, фильтр был бы кнопкой без эффекта.
            'managers' => $seesAll ? $this->managerOptions() : [],
            'seesAll' => $seesAll,
            // Выбранные товары приезжают целиком: в URL только id, а рисовать
            // фильтр нужно с названиями.
            'selectedProducts' => $this->selectedProducts($productIds),
            'filters' => [
                'search' => $search,
                'statuses' => $this->values($request, 'statuses', 'status'),
                'partner_ids' => $this->ids($request, 'partner_ids'),
                'company_ids' => $this->values($request, 'company_ids'),
                'manager_ids' => $seesAll ? $this->ids($request, 'manager_ids') : [],
                'organization_ids' => $this->values($request, 'organization_ids', 'organization_id'),
                'warehouse_ids' => $this->values($request, 'warehouse_ids', 'warehouse_id'),
                'product_ids' => $productIds,
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'amount_from' => $request->input('amount_from'),
                'amount_to' => $request->input('amount_to'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Скоуп клиентов актора с учётом фильтра по менеджеру.
     *
     * Менеджер сужает не документ, а набор клиентов, поэтому живёт здесь, а не
     * в applyCommonFilters: тем же скоупом собираются справочники фильтров, и
     * выбор менеджера заодно сужает список его партнёров и контрагентов.
     *
     * Фильтр по партнёру сюда не входит намеренно — иначе, выбрав одного,
     * второго уже не добавить: справочник схлопнулся бы до выбранного.
     *
     * @return Builder<User>
     */
    private function visibleClients(Request $request, User $actor): Builder
    {
        $query = User::query()->visibleInCrm($actor)->select('users.id');

        // Менеджера подставляет только РОП: у рядового менеджера скоуп и так
        // сведён к своим клиентам, а чужой id в запросе не должен ничего давать.
        if ($this->seesAllClients($request)) {
            $managerIds = $this->ids($request, 'manager_ids');

            if ($managerIds !== []) {
                $query->whereIn('users.personal_manager_id', $managerIds);
            }
        }

        return $query;
    }

    /**
     * Мультивыбор по числовой колонке. Псевдо-значение 'none' — «пусто»
     * (документ без организации, без склада, без контрагента).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $values
     */
    private function applyIdFilter(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $withoutNone = array_values(array_filter($values, fn (string $value): bool => $value !== 'none'));
        $ids = array_values(array_filter(array_map('intval', $withoutNone), fn (int $id): bool => $id > 0));
        $hasNone = count($withoutNone) !== count($values);

        if ($ids === [] && ! $hasNone) {
            return;
        }

        $query->where(function (Builder $inner) use ($column, $ids, $hasNone): void {
            if ($ids !== []) {
                $inner->whereIn($column, $ids);
            }

            if ($hasNone) {
                $ids === [] ? $inner->whereNull($column) : $inner->orWhereNull($column);
            }
        });
    }

    /**
     * Значения мультивыбора из запроса. Старые ссылки со скалярным параметром
     * (?status=new) продолжают работать — их читает $legacyKey.
     *
     * @return list<string>
     */
    private function values(Request $request, string $key, ?string $legacyKey = null): array
    {
        $input = $request->input($key);

        if ($input === null && $legacyKey !== null) {
            $input = $request->input($legacyKey);
        }

        if ($input === null || $input === '') {
            return [];
        }

        $values = array_map(
            fn (mixed $value): string => trim((string) $value),
            is_array($input) ? $input : [$input],
        );

        return array_values(array_unique(array_filter($values, fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<int>
     */
    private function ids(Request $request, string $key): array
    {
        $ids = array_map('intval', $this->values($request, $key));

        return array_values(array_filter($ids, fn (int $id): bool => $id > 0));
    }

    /**
     * Партнёры — клиенты, у которых в этом журнале есть хотя бы один документ.
     *
     * Берём из самих документов, а не из списка клиентов: у РОПа клиентов
     * восемь сотен, и большинство в конкретном журнале не встречается ни разу.
     *
     * @param  'orders'|'shipments'  $table
     * @param  Builder<User>  $clients
     * @return list<array{id: int, name: string}>
     */
    private function partnerOptions(string $table, Builder $clients): array
    {
        // Сначала голые id из журнала, потом сами карточки: join с users по всей
        // таблице документов заставил бы MySQL читать строки, а так хватает
        // индекса по user_id.
        $ids = DB::table($table)
            ->whereNull($table.'.deleted_at')
            ->whereIn($table.'.user_id', (clone $clients))
            ->distinct()
            ->pluck($table.'.user_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'erp_name', 'email'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->display_name,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Контрагенты — юрлица, на которые проведены документы журнала.
     *
     * @param  'orders'|'shipments'  $table
     * @param  Builder<User>  $clients
     * @return list<array{id: int, name: string}>
     */
    private function companyOptions(string $table, Builder $clients): array
    {
        $ids = DB::table($table)
            ->whereNull($table.'.deleted_at')
            ->whereIn($table.'.user_id', (clone $clients))
            ->whereNotNull($table.'.company_id')
            ->distinct()
            ->pluck($table.'.company_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return Company::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'legal_name', 'tax_id'])
            ->map(fn (Company $company): array => [
                'id' => (int) $company->getKey(),
                'name' => (string) ($company->name ?: $company->legal_name ?: ($company->tax_id ? 'ИНН '.$company->tax_id : 'Контрагент #'.$company->getKey())),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function managerOptions(): array
    {
        return PersonalManager::query()
            ->active()
            ->whereHas('users')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PersonalManager $manager): array => [
                'id' => (int) $manager->getKey(),
                'name' => (string) $manager->name,
            ])
            ->all();
    }

    /**
     * Подсказки товаров для фильтра «товар в документе».
     *
     * Отдельный маршрут вместо `crm.products.search`: тот закрыт правом
     * `crm-analytics.view`, которого у рядового менеджера может не быть, а
     * журналы документов открыты по `crm-clients.view`.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::search($query)
            ->take(20)
            ->get()
            ->load(['media', 'brand:id,name'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'brand_name' => $product->brand?->name,
            ]);

        return response()->json($products);
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array{id: int, name: string, sku: string|null, image_url: string, brand_name: string|null}>
     */
    private function selectedProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->with(['media', 'brand:id,name'])
            ->get(['id', 'name', 'sku', 'brand_id'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'brand_name' => $product->brand?->name,
            ])
            ->all();
    }

    private function search(Request $request): ?string
    {
        $search = trim((string) $request->input('search', ''));

        return $search === '' ? null : mb_substr($search, 0, 120);
    }

    public function order(Request $request, int $order): Response
    {
        $actor = $this->crmActor($request);

        /** @var Order $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::ORDER, $order);

        $model->load([
            'user:id,name,erp_name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            // is_stub обязателен в выборке: без него незаведённое юрлицо
            // показалось бы менеджеру голым UUID-ом вместо названия.
            'organization:id,name,is_stub',
            'warehouse:id,name',
            'items.product:id,name,slug,sku',
            'shipments' => fn ($query) => $query->orderByDesc('date'),
        ]);

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::ORDER,
                'id' => (int) $model->getKey(),
                'title' => 'Заказ №'.($model->erp_number ?: $model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => $model->erp_number,
                'status_label' => $model->status->label(),
                'status_color' => $model->status->color(),
                'date_label' => ($model->erp_created_at ?? $model->created_at)?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                'comment' => $model->comment,
                'manager_comment' => $model->manager_comment,
                'delivery_address' => $model->delivery_address,
                'organization' => $this->organization($model),
                'warehouse' => $this->warehouse($model),
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.orders.show', $model->getKey()) : null,
                'items' => $model->items->map(fn (OrderItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->name ?: $item->product?->name ?: 'Позиция №'.$item->getKey(),
                    'sku' => $item->product?->sku,
                    'brand' => $item->brand_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'price_label' => $this->money((float) $item->final_price, $model->currency_code),
                    'total_label' => $this->money((float) $item->subtotal, $model->currency_code),
                ])->all(),
                'related' => $model->shipments->map(fn (Shipment $shipment): array => [
                    'type' => CrmEntityMap::SHIPMENT,
                    'id' => (int) $shipment->getKey(),
                    'title' => 'Реализация №'.($shipment->erp_number ?: $shipment->number ?: $shipment->getKey()),
                    'date_label' => ($shipment->erp_created_at ?? $shipment->date)?->format('d.m.Y'),
                    'total_label' => $this->money((float) $shipment->total_amount, $shipment->currency_code),
                    'url' => route('crm.shipments.show', $shipment->getKey()),
                ])->all(),
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    public function shipment(Request $request, int $shipment): Response
    {
        $actor = $this->crmActor($request);

        /** @var Shipment $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::SHIPMENT, $shipment);

        $model->load([
            'user:id,name,erp_name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            // is_stub обязателен в выборке: без него незаведённое юрлицо
            // показалось бы менеджеру голым UUID-ом вместо названия.
            'organization:id,name,is_stub',
            'warehouse:id,name',
            'items.product:id,name,slug,sku',
        ]);

        // Заказы, по которым сделана отгрузка: связь идёт через order_uuid
        // в позициях, а не прямой ссылкой на заказ — одна отгрузка может
        // закрывать несколько заказов.
        $related = [];

        foreach ($model->getRelatedOrders() as $order) {
            /** @var Order $order */
            $related[] = [
                'type' => CrmEntityMap::ORDER,
                'id' => (int) $order->getKey(),
                'title' => 'Заказ №'.($order->erp_number ?: $order->number ?: $order->getKey()),
                'date_label' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y'),
                'total_label' => $this->money((float) $order->total_amount, $order->currency_code),
                'url' => route('crm.orders.show', $order->getKey()),
            ];
        }

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::SHIPMENT,
                'id' => (int) $model->getKey(),
                'title' => 'Реализация №'.($model->erp_number ?: $model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => $model->erp_number,
                'status_label' => $model->status_label,
                'status_color' => $model->status === 'completed' ? 'green' : 'blue',
                'date_label' => ($model->erp_created_at ?? $model->date)?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                'comment' => null,
                'manager_comment' => null,
                'delivery_address' => null,
                'organization' => $this->organization($model),
                'warehouse' => $this->warehouse($model),
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.shipments.show', $model->getKey()) : null,
                'items' => $model->items->map(fn (ShipmentItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->product_name_snapshot ?: $item->product?->name ?: 'Позиция №'.$item->getKey(),
                    'sku' => $item->product?->sku,
                    'brand' => $item->brand_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'price_label' => $this->money((float) $item->price, $model->currency_code),
                    'total_label' => $this->money((float) $item->total, $model->currency_code),
                ])->all(),
                'related' => $related,
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    /**
     * Наша организация документа — юрлицо, на которое 1С его провела.
     *
     * Показ гейтит `ORGANIZATIONS_ENABLED`, ровно как в админке и ЛК: приём из 1С
     * работает всегда, а витрина включается, когда справочник заполнен.
     *
     * @param  Order|Shipment  $model
     * @return array{name: string, is_stub: bool}|null
     */
    private function organization(\Illuminate\Database\Eloquent\Model $model): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $model->getRelationValue('organization');

        if (! $organization instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }

        return [
            'name' => (string) $organization->getAttribute('name'),
            'is_stub' => (bool) $organization->getAttribute('is_stub'),
        ];
    }

    /**
     * Склад отгрузки документа — определён 1С, сайт только показывает.
     *
     * @param  Order|Shipment  $model
     */
    private function warehouse(\Illuminate\Database\Eloquent\Model $model): ?string
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $warehouse = $model->getRelationValue('warehouse');

        return $warehouse instanceof \Illuminate\Database\Eloquent\Model
            ? (string) $warehouse->getAttribute('name')
            : null;
    }

    /**
     * Клиент документа — шапка карточки и ссылка обратно в его ленту.
     *
     * @return array<string, mixed>|null
     */
    private function clientPayload(?\App\Models\User $client): ?array
    {
        if ($client === null) {
            return null;
        }

        return [
            'id' => (int) $client->getKey(),
            'name' => $client->display_name,
            'personal_name' => $client->personal_name_if_differs,
            'email' => $client->email,
            'phone' => $client->phone,
            'url' => route('crm.clients.show', $client->getKey()),
        ];
    }

    /**
     * Сумма с валютой — форматируется на сервере, как и даты.
     */
    private function money(float $amount, ?string $currencyCode): string
    {
        $symbol = match ($currencyCode) {
            'RUB', null => '₽',
            'KZT' => '₸',
            'BYN' => 'Br',
            default => $currencyCode,
        };

        return number_format($amount, 2, ',', ' ').' '.$symbol;
    }
}
