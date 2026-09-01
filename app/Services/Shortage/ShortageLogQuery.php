<?php

namespace App\Services\Shortage;

use App\Enums\Crm\CrmScope;
use App\Enums\Shortage\ShortageReasonCategory;
use App\Models\OrderItem;
use App\Models\ShortageReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Журнал недоборов: отменённые строки заказов с разрезами по партнёрам и товарам.
 *
 * Источник — сами строки заказа (`order_items.cancelled`), отдельной таблицы-лога
 * нет: 1С присылает состав документа целиком, и копия строки разъезжалась бы
 * с оригиналом при первой же правке количества.
 *
 * Причину отмены протокол 1С не передаёт — её ставит человек, выбирая строку
 * справочника (см. {@see ShortageReason}). Категория причины
 * ({@see ShortageReasonCategory}) держит сводную часть: чипы, цвета, легенду.
 *
 * Сводки считаются тем же фильтром, что и журнал: цифра во вкладке «По товарам»
 * обязана сходиться со списком строк — иначе менеджер не поверит ни той, ни другой.
 */
class ShortageLogQuery
{
    /** Период по умолчанию, дней: недобор старше квартала уже не рабочая задача. */
    public const DEFAULT_PERIOD_DAYS = 90;

    /**
     * Дата события журнала.
     *
     * У отмен, случившихся до появления журнала, собственной даты нет — их
     * дату сайт не знает и не выдумывает. Чтобы такие строки не выпадали
     * из раздела совсем, они встают по дате заказа: отмена случилась
     * заведомо позже неё, и порядок «свежее сверху» сохраняется.
     */
    private const EVENT_DATE = 'COALESCE(order_items.cancelled_at, orders.erp_created_at, orders.created_at)';

    /**
     * Нормализованные фильтры из запроса.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filters(array $input, User $actor): array
    {
        $scope = CrmScope::resolve($input['scope'] ?? null, $actor);

        $from = $this->parseDate($input['from'] ?? null)
            ?? Carbon::today()->subDays(self::DEFAULT_PERIOD_DAYS);
        $to = $this->parseDate($input['to'] ?? null) ?? Carbon::today();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $category = (string) ($input['category'] ?? '');
        $state = (string) ($input['state'] ?? '');
        $fulfillment = (string) ($input['fulfillment'] ?? '');

        return [
            'scope' => $scope->value,
            // Состояние строки: 'active' — в работе (по умолчанию), 'archived' —
            // выведенные из работы без разметки, 'all' — и те и другие.
            'state' => in_array($state, ['archived', 'all'], true) ? $state : 'active',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            // Категория причины — быстрый фильтр чипов; 'none' — неразобранные отмены.
            'category' => $category === 'none' || in_array($category, ShortageReasonCategory::values(), true) ? $category : '',
            'reason_id' => $this->intOrNull($input['reason_id'] ?? null),
            'manager_id' => $this->intOrNull($input['manager_id'] ?? null),
            'user_id' => $this->intOrNull($input['user_id'] ?? null),
            'company_id' => $this->intOrNull($input['company_id'] ?? null),
            'product_id' => $this->intOrNull($input['product_id'] ?? null),
            'search' => trim((string) ($input['search'] ?? '')),
            'tab' => in_array($input['tab'] ?? '', ['partners', 'products', 'reasons'], true)
                ? (string) $input['tab']
                : 'log',
            // База расчёта удовлетворения: по умолчанию только отгруженные заказы —
            // у заказа в сборке состав ещё изменится, и процент был бы завышен.
            'fulfillment' => $fulfillment === 'all' ? 'all' : 'settled',
        ];
    }

    /**
     * Базовый запрос журнала: только отменённые строки живых заказов.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<OrderItem>
     */
    public function query(array $filters, User $actor, bool $seesDepartment): Builder
    {
        $scope = CrmScope::resolve($filters['scope'], $actor);

        $managerId = $seesDepartment && $filters['manager_id'] !== null
            ? $filters['manager_id']
            : null;

        $query = $this->visible($actor, $seesDepartment, $scope, $managerId)
            ->whereBetween(DB::raw(self::EVENT_DATE), [
                Carbon::parse($filters['from'])->startOfDay(),
                Carbon::parse($filters['to'])->endOfDay(),
            ]);

        if ($filters['state'] === 'archived') {
            $query->whereNotNull('order_items.cancel_archived_at');
        } elseif ($filters['state'] !== 'all') {
            $query->whereNull('order_items.cancel_archived_at');
        }

        if ($filters['category'] === 'none') {
            $query->whereNull('order_items.cancel_reason_id');
        } elseif ($filters['category'] !== '') {
            $query->whereHas('cancelReason', fn (Builder $r) => $r->where('shortage_reasons.category', $filters['category']));
        }

        if ($filters['reason_id'] !== null) {
            $query->where('order_items.cancel_reason_id', $filters['reason_id']);
        }

        if ($filters['user_id'] !== null) {
            $query->where('orders.user_id', $filters['user_id']);
        }

        if ($filters['company_id'] !== null) {
            $query->where('orders.company_id', $filters['company_id']);
        }

        if ($filters['product_id'] !== null) {
            $query->where('order_items.product_id', $filters['product_id']);
        }

        if ($filters['search'] !== '') {
            $needle = '%'.$filters['search'].'%';

            $query->where(function (Builder $q) use ($needle) {
                $q->where('order_items.name', 'like', $needle)
                    ->orWhereHas('order', fn (Builder $o) => $o
                        ->where('orders.erp_number', 'like', $needle)
                        ->orWhere('orders.number', 'like', $needle))
                    ->orWhereHas('order.user', fn (Builder $u) => $u
                        ->where('users.name', 'like', $needle)
                        ->orWhere('users.erp_name', 'like', $needle));
            });
        }

        return $query;
    }

    /**
     * Страница журнала.
     *
     * @param  Builder<OrderItem>  $query
     * @return LengthAwarePaginator<int, OrderItem>
     */
    public function page(Builder $query, int $perPage = 50): LengthAwarePaginator
    {
        return $query
            ->with([
                'order:id,number,erp_number,user_id,company_id,status,erp_created_at,created_at',
                'order.user:id,name,erp_name,personal_manager_id',
                'order.user.personalManager:id,name',
                'order.company:id,name',
                'product:id,name,sku,slug',
                'cancelSourceUser:id,name',
                'cancelReason',
            ])
            ->orderByDesc(DB::raw(self::EVENT_DATE))
            ->orderByDesc('order_items.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Итоги шапки: сколько строк, штук и денег и сколько из них разобрано.
     *
     * @param  Builder<OrderItem>  $query
     * @return array<string, float|int>
     */
    public function totals(Builder $query): array
    {
        $row = (clone $query)
            ->toBase()
            ->select(DB::raw('COUNT(*) as lines_count'))
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as amount')
            ->selectRaw('SUM(CASE WHEN order_items.cancel_reason_id IS NULL THEN 1 ELSE 0 END) as unmarked_count')
            ->reorder()
            ->first();

        return [
            'lines_count' => (int) ($row->lines_count ?? 0),
            'quantity' => (int) ($row->quantity ?? 0),
            'amount' => (float) ($row->amount ?? 0),
            'unmarked_count' => (int) ($row->unmarked_count ?? 0),
        ];
    }

    /**
     * Чипы быстрых фильтров: количество, штуки и деньги по категориям причин.
     *
     * Считаются по тому же отбору, что и журнал, но БЕЗ фильтра категории —
     * иначе выбранный чип обнулил бы соседние, и вернуться к общей картине
     * одним кликом стало бы невозможно.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function chips(array $filters, User $actor, bool $seesDepartment): array
    {
        $base = $this->query(
            array_merge($filters, ['category' => '', 'reason_id' => null]),
            $actor,
            $seesDepartment,
        );

        $rows = $base
            ->toBase()
            ->leftJoin('shortage_reasons', 'shortage_reasons.id', '=', 'order_items.cancel_reason_id')
            ->groupBy('shortage_reasons.category')
            ->select('shortage_reasons.category as reason_category')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as amount')
            ->reorder()
            ->get()
            ->keyBy(fn ($row) => $row->reason_category ?? 'none');

        $chips = [];

        foreach (ShortageReasonCategory::ordered() as $category) {
            $row = $rows->get($category->value);

            $chips[] = [
                'value' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
                'color' => $category->color(),
                'lines_count' => (int) ($row->lines_count ?? 0),
                'quantity' => (int) ($row->quantity ?? 0),
                'amount' => (float) ($row->amount ?? 0),
            ];
        }

        $none = $rows->get('none');

        // Неразобранные — такой же чип: это рабочая очередь менеджера,
        // и она должна быть в одном ряду с остальными, а не в отдельном углу.
        $chips[] = [
            'value' => 'none',
            'label' => 'Без причины',
            'description' => 'Отмена ещё не разобрана: причину должен выбрать менеджер.',
            'color' => 'gray',
            'lines_count' => (int) ($none->lines_count ?? 0),
            'quantity' => (int) ($none->quantity ?? 0),
            'amount' => (float) ($none->amount ?? 0),
        ];

        return $chips;
    }

    /**
     * Сводка по партнёрам: у кого чаще срывается заказ.
     *
     * @param  Builder<OrderItem>  $query
     * @return list<array<string, mixed>>
     */
    public function byPartners(Builder $query, int $limit = 100): array
    {
        return (clone $query)
            ->toBase()
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('personal_managers', 'personal_managers.id', '=', 'users.personal_manager_id')
            ->leftJoin('shortage_reasons', 'shortage_reasons.id', '=', 'order_items.cancel_reason_id')
            // Группируем по одному ключу, подписи берём агрегатом: у MySQL
            // с ONLY_FULL_GROUP_BY имя партнёра иначе пришлось бы тащить
            // в GROUP BY, и переименование в 1С разбило бы группу надвое.
            ->groupBy('orders.user_id')
            ->select(DB::raw('orders.user_id as user_id'))
            ->selectRaw('MAX(users.name) as user_name')
            ->selectRaw('MAX(users.erp_name) as user_erp_name')
            ->selectRaw('MAX(personal_managers.name) as manager_name')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as amount')
            ->selectRaw($this->categoryBreakdownSelect())
            ->selectRaw('SUM(CASE WHEN order_items.cancel_reason_id IS NULL THEN 1 ELSE 0 END) as unmarked_count')
            ->selectRaw('MAX('.self::EVENT_DATE.') as last_cancelled_at')
            ->reorder()
            ->orderByDesc('lines_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id ? (int) $row->user_id : null,
                'name' => $row->user_erp_name ?: ($row->user_name ?: 'Без партнёра'),
                'manager' => $row->manager_name ?: '—',
                'lines_count' => (int) $row->lines_count,
                'orders_count' => (int) $row->orders_count,
                'quantity' => (int) $row->quantity,
                'amount' => (float) $row->amount,
                'categories' => $this->categoryBreakdown($row),
                'unmarked_count' => (int) $row->unmarked_count,
                'last_cancelled_at' => $row->last_cancelled_at
                    ? Carbon::parse($row->last_cancelled_at)->format('d.m.Y')
                    : null,
            ])
            ->all();
    }

    /**
     * Сводка по товарам: что чаще всего не удаётся собрать.
     *
     * @param  Builder<OrderItem>  $query
     * @return list<array<string, mixed>>
     */
    public function byProducts(Builder $query, int $limit = 100): array
    {
        return (clone $query)
            ->toBase()
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('shortage_reasons', 'shortage_reasons.id', '=', 'order_items.cancel_reason_id')
            // Ключ группировки — товар; строки с неизвестным сайту товаром
            // (product_id = NULL) собираются по снимку названия. Подписи берём
            // агрегатом: снимок имени в строке заказа мог устареть, и с ним
            // в GROUP BY один товар распался бы на несколько строк отчёта.
            ->groupByRaw('order_items.product_id, CASE WHEN order_items.product_id IS NULL THEN order_items.name ELSE NULL END')
            ->select(DB::raw('order_items.product_id as product_id'))
            ->selectRaw('MAX(order_items.name) as item_name')
            ->selectRaw('MAX(products.name) as product_name')
            ->selectRaw('MAX(products.sku) as sku')
            ->selectRaw('MAX(products.slug) as slug')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COUNT(DISTINCT orders.user_id) as partners_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as amount')
            ->selectRaw($this->categoryBreakdownSelect())
            ->selectRaw('SUM(CASE WHEN order_items.cancel_reason_id IS NULL THEN 1 ELSE 0 END) as unmarked_count')
            ->selectRaw('MAX('.self::EVENT_DATE.') as last_cancelled_at')
            ->reorder()
            ->orderByDesc('lines_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id ? (int) $row->product_id : null,
                'name' => $row->product_name ?: $row->item_name,
                'sku' => $row->sku,
                'slug' => $row->slug,
                'lines_count' => (int) $row->lines_count,
                'partners_count' => (int) $row->partners_count,
                'quantity' => (int) $row->quantity,
                'amount' => (float) $row->amount,
                'categories' => $this->categoryBreakdown($row),
                'unmarked_count' => (int) $row->unmarked_count,
                'last_cancelled_at' => $row->last_cancelled_at
                    ? Carbon::parse($row->last_cancelled_at)->format('d.m.Y')
                    : null,
            ])
            ->all();
    }

    /**
     * Счётчик бокового меню: неразобранные отмены за рабочий период.
     *
     * Считается по видимости сотрудника — менеджер видит цифру по своим
     * партнёрам, руководитель по отделу.
     */
    public function unmarkedCount(User $actor): int
    {
        return $this->visible($actor, $actor->can('crm-department.view'))
            ->whereNull('order_items.cancel_reason_id')
            ->whereNull('order_items.cancel_archived_at')
            ->where(DB::raw(self::EVENT_DATE), '>=', Carbon::today()->subDays(self::DEFAULT_PERIOD_DAYS))
            ->count();
    }

    /**
     * Справочник причин для выпадающего списка и легенды.
     *
     * Отключённые причины отдаются тоже: строка, размеченная год назад, обязана
     * показывать свою причину, даже если РОП убрал её из списка. Выбрать такую
     * заново нельзя — это решает фронт по флагу `is_active`.
     *
     * @return list<array<string, mixed>>
     */
    public function reasonOptions(): array
    {
        return ShortageReason::query()
            ->ordered()
            ->get()
            ->map(fn (ShortageReason $reason) => $reason->toOption())
            ->all();
    }

    /**
     * Легенда: категории причин с пояснениями.
     *
     * @return list<array{value: string, label: string, description: string, color: string}>
     */
    public function categoryOptions(): array
    {
        return ShortageReasonCategory::options();
    }

    /**
     * Отменённые строки, доступные сотруднику, — без фильтров экрана.
     *
     * Граница проходит по праву `crm-department.view`; разрез из запроса лишь
     * сужает уже разрешённое (см. {@see CrmScope}). Фильтр по конкретному
     * менеджеру доступен тем, кто видит отдел, — иначе это обход скоупа.
     *
     * Разметку строки проверяем этим же запросом, но без периода: причину ставят
     * и на давнюю отмену, найденную фильтром за прошлый квартал.
     *
     * @return Builder<OrderItem>
     */
    public function visible(
        User $actor,
        bool $seesDepartment,
        ?CrmScope $scope = null,
        ?int $managerId = null,
    ): Builder {
        // JOIN, а не whereHas: дата заказа нужна и в отборе периода (у давних
        // отмен собственной даты нет), и в сортировке журнала.
        $query = OrderItem::query()
            ->select('order_items.*')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('order_items.cancelled', true);

        $managerId = $this->scopedManagerId($actor, $seesDepartment, $scope, $managerId);

        if ($managerId === false) {
            return $query->whereRaw('1 = 0');
        }

        if ($managerId !== null) {
            $query->whereHas('order.user', fn (Builder $u) => $u->where('users.personal_manager_id', $managerId));
        }

        return $query;
    }

    /**
     * Чьи партнёры попадают в выборку.
     *
     * `null` — весь отдел, `false` — никто (сотрудник без карточки менеджера
     * в разрезе «мои»: за ним не закреплён ни один партнёр).
     */
    public function scopedManagerId(
        User $actor,
        bool $seesDepartment,
        ?CrmScope $scope = null,
        ?int $managerId = null,
    ): int|false|null {
        $scope ??= $seesDepartment ? CrmScope::DEPARTMENT : CrmScope::MINE;

        if ($managerId === null && (! $seesDepartment || $scope->isMine())) {
            $managerId = $actor->managerProfile?->id;

            // CrmScope такому сотруднику отдаёт DEPARTMENT, сюда попадаем
            // только при отсутствии права видеть отдел.
            if ($managerId === null && ! $seesDepartment) {
                return false;
            }
        }

        return $managerId;
    }

    /**
     * Разбивка по категориям причин — шесть колонок агрегата вместо подзапроса.
     *
     * Значения категорий приходят из перечисления, а не из запроса, поэтому
     * подстановка в SQL безопасна: чужой строке взяться неоткуда.
     */
    private function categoryBreakdownSelect(): string
    {
        $parts = array_map(
            fn (ShortageReasonCategory $category) => sprintf(
                "SUM(CASE WHEN shortage_reasons.category = '%s' THEN 1 ELSE 0 END) as category_%s",
                $category->value,
                $category->value,
            ),
            ShortageReasonCategory::ordered(),
        );

        return implode(', ', $parts);
    }

    /**
     * @return list<array{value: string, label: string, color: string, lines_count: int}>
     */
    private function categoryBreakdown(object $row): array
    {
        $breakdown = [];

        foreach (ShortageReasonCategory::ordered() as $category) {
            $key = 'category_'.$category->value;
            $count = (int) ($row->{$key} ?? 0);

            if ($count === 0) {
                continue;
            }

            $breakdown[] = [
                'value' => $category->value,
                'label' => $category->label(),
                'color' => $category->color(),
                'lines_count' => $count,
            ];
        }

        return $breakdown;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
