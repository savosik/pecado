<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Enums\DeliveryMethod;
use App\Models\GoodsIssue;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Список реализаций для мастера отправки: обогащение, группировка, сортировка.
 *
 * Голый номер реализации кладовщику ничего не говорит: по нему не понять, на какой
 * стадии заказы, собран ли груз, куда он едет и не пытались ли его уже отправить.
 * Поэтому строка собирается из четырёх источников — заказы (статусы, способ и адрес
 * доставки), расходный ордер (состояние сборки), сама реализация и история прошлых
 * отправок.
 *
 * Оплаты здесь намеренно нет: деньги — забота отдела продаж, склад грузит по документу.
 *
 * Всё грузится пакетно: полторы сотни строк × четыре связи в лоб дали бы
 * несколько сотен запросов.
 */
class AvailableShipmentsPresenter
{
    /** Все заказы реализации — самовывоз: перевозчик такому грузу не нужен. */
    public const KIND_PICKUP = 'pickup';

    /** Груз едет к клиенту — обычный кандидат на отправку. */
    public const KIND_DELIVERY = 'delivery';

    /** Заказы разошлись в способе доставки — решает человек. */
    public const KIND_MIXED = 'mixed';

    /** Заказов за реализацией нет, способ неизвестен. */
    public const KIND_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const GROUP_BY = ['client', 'address', 'date', 'kind'];

    /** @var list<string> */
    public const SORTS = ['date_desc', 'date_asc', 'amount_desc', 'weight_desc', 'number_asc'];

    public function __construct(private readonly DeliveryWeightCalculator $weights) {}

    /**
     * Реализации, разложенные на две вкладки: к отправке и самовывоз.
     *
     * Самовывоз выносится отдельно, потому что перевозчик такому грузу не нужен
     * вовсе, а в общем списке он занимает треть строк. Но и прятать его нельзя:
     * способ доставки в заказе бывает выбран ошибочно, и тогда груз всё-таки едет ТК.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @param  array<string, mixed>  $filters  постфильтры по обогащённым полям
     * @return array{delivery: list<array<string, mixed>>, pickup: list<array<string, mixed>>}
     */
    public function present(
        Collection $shipments,
        string $groupBy = 'client',
        string $sort = 'date_desc',
        array $filters = [],
    ): array {
        if ($shipments->isEmpty()) {
            return ['delivery' => [], 'pickup' => []];
        }

        $orders = $this->loadOrders($shipments);
        $goodsIssues = $this->loadGoodsIssues($shipments);
        $previous = $this->loadPreviousDeliveries($shipments);
        $hidden = $this->loadHidden($shipments);

        $rows = $shipments
            ->map(fn (Shipment $shipment): array => $this->presentRow(
                $shipment,
                $orders,
                $goodsIssues,
                $previous,
                $hidden,
            ))
            ->filter(fn (array $row): bool => $this->passesFilters($row, $filters))
            ->values();

        // Смешанные и неизвестные остаются в основной вкладке: их надо разобрать,
        // а не спрятать. Уходит только явный, ни в чём не сомневающийся самовывоз.
        [$pickup, $delivery] = $rows->partition(
            static fn (array $row): bool => $row['delivery_kind'] === self::KIND_PICKUP,
        );

        return [
            'delivery' => $this->group($delivery, $groupBy, $sort),
            'pickup' => $this->group($pickup, $groupBy, $sort),
        ];
    }

    /**
     * Плоский список без группировки — для мультивыбора в форме создания отправки.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return list<array<string, mixed>>
     */
    public function flat(Collection $shipments): array
    {
        if ($shipments->isEmpty()) {
            return [];
        }

        $orders = $this->loadOrders($shipments);
        $goodsIssues = $this->loadGoodsIssues($shipments);
        $previous = $this->loadPreviousDeliveries($shipments);
        $hidden = $this->loadHidden($shipments);

        return $shipments
            ->map(fn (Shipment $shipment): array => $this->presentRow(
                $shipment,
                $orders,
                $goodsIssues,
                $previous,
                $hidden,
            ))
            ->values()
            ->all();
    }

    // ─────────────────────────── Группировка ───────────────────────────

    /**
     * Разложить строки по группам выбранного разреза.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function group(Collection $rows, string $groupBy, string $sort): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        return $rows
            ->groupBy(fn (array $row): string => $this->groupKey($row, $groupBy))
            ->map(function (Collection $group, string $key) use ($groupBy, $sort): array {
                $first = $group->first();

                return [
                    'key' => $key,
                    'title' => $this->groupTitle($first, $groupBy),
                    'subtitle' => $this->groupSubtitle($group, $groupBy),
                    // user_id есть только у разреза по клиенту: в остальных группа
                    // может смешивать клиентов, и запрет выбора работает построчно.
                    'user_id' => $groupBy === 'client' ? $first['user_id'] : null,
                    'shipments_count' => $group->count(),
                    'total_weight' => (int) $group->sum('weight'),
                    'total_amount' => round((float) $group->sum('amount'), 2),
                    'shipments' => $this->sortRows($group, $sort)->values()->all(),
                    'latest_date' => $group->max('date'),
                ];
            })
            // Группы всегда по хронологии свежести — так список читается одинаково
            // в любом разрезе: сверху то, что отгрузили последним.
            ->sortByDesc('latest_date')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupKey(array $row, string $groupBy): string
    {
        return match ($groupBy) {
            'address' => 'address-'.md5((string) ($row['delivery_address'] ?? '')),
            'date' => 'date-'.(string) ($row['date'] ?? 'unknown'),
            'kind' => 'kind-'.$row['delivery_kind'],
            default => 'client-'.(string) ($row['user_id'] ?? 'none'),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupTitle(array $row, string $groupBy): string
    {
        return match ($groupBy) {
            'address' => (string) ($row['delivery_address'] ?: 'Адрес не указан'),
            'date' => (string) ($row['date_label'] ?: 'Дата не указана'),
            'kind' => match ($row['delivery_kind']) {
                self::KIND_PICKUP => 'Самовывоз',
                self::KIND_DELIVERY => 'Доставка',
                self::KIND_MIXED => 'Способы доставки различаются',
                default => 'Способ доставки неизвестен',
            },
            default => (string) ($row['client'] ?: 'Без клиента'),
        };
    }

    /**
     * Вторая строка заголовка: чего в разрезе не хватает, чтобы понять группу.
     *
     * @param  Collection<int, array<string, mixed>>  $group
     */
    private function groupSubtitle(Collection $group, string $groupBy): ?string
    {
        $clients = $group->pluck('client')->filter()->unique();

        return match ($groupBy) {
            // В разрезе по клиенту адрес важнее всего: по нему видно, поедет ли
            // груз одной машиной или это две разные отправки.
            'client' => $this->summarize($group->pluck('delivery_address')->filter()->unique(), 'адрес'),
            // В остальных разрезах группа может смешивать клиентов — их и показываем.
            default => $this->summarize($clients, 'клиент'),
        };
    }

    /**
     * @param  Collection<int, string>  $values
     */
    private function summarize(Collection $values, string $noun): ?string
    {
        if ($values->isEmpty()) {
            return null;
        }

        if ($values->count() === 1) {
            return (string) $values->first();
        }

        return $values->first().' и ещё '.($values->count() - 1).' '.Str::plural($noun, $values->count() - 1);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'date_asc' => $rows->sortBy('date'),
            'amount_desc' => $rows->sortByDesc('amount'),
            'weight_desc' => $rows->sortByDesc('weight'),
            'number_asc' => $rows->sortBy('number'),
            default => $rows->sortByDesc('date'),
        };
    }

    // ─────────────────────────── Постфильтры ───────────────────────────

    /**
     * Фильтры по полям, которых нет в таблице реализаций.
     *
     * Адрес и совокупный способ доставки собираются из заказов уже здесь,
     * поэтому и отсекаются здесь же, а не в SQL.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function passesFilters(array $row, array $filters): bool
    {
        $address = trim((string) ($filters['address'] ?? ''));

        if ($address !== '' && ! Str::contains((string) ($row['delivery_address'] ?? ''), $address, ignoreCase: true)) {
            return false;
        }

        /** @var list<string> $kinds */
        $kinds = $filters['delivery_kinds'] ?? [];

        if ($kinds !== [] && ! in_array($row['delivery_kind'], $kinds, true)) {
            return false;
        }

        if (($filters['only_without_goods_issue'] ?? false) && $row['goods_issue'] !== null) {
            return false;
        }

        if (($filters['only_retry'] ?? false) && $row['previous_delivery'] === null) {
            return false;
        }

        return true;
    }

    // ─────────────────────────── Строка ───────────────────────────

    /**
     * @param  array<string, Order>  $orders
     * @param  array<string, GoodsIssue>  $goodsIssues
     * @param  array<int, array<string, mixed>>  $previous
     * @param  array<int, array<string, mixed>>  $hidden
     * @return array<string, mixed>
     */
    private function presentRow(
        Shipment $shipment,
        array $orders,
        array $goodsIssues,
        array $previous,
        array $hidden,
    ): array {
        $breakdown = $this->weights->breakdown($shipment);
        $orderUuids = $shipment->items->pluck('order_uuid')->filter()->unique()->values();

        /** @var Collection<int, Order> $relatedOrders */
        $relatedOrders = $orderUuids
            ->map(fn (string $uuid): ?Order => $orders[$uuid] ?? null)
            ->filter()
            ->values();

        $goodsIssue = $orderUuids
            ->map(fn (string $uuid): ?GoodsIssue => $goodsIssues[$uuid] ?? null)
            ->filter()
            ->first();

        return [
            'id' => (int) $shipment->getKey(),
            'number' => $shipment->erp_number ?: $shipment->number ?: (string) $shipment->getKey(),
            'date' => $shipment->date?->format('Y-m-d'),
            'date_label' => $shipment->date?->format('d.m.Y'),
            'user_id' => $shipment->user_id,
            'client' => $shipment->user?->erp_name ?: $shipment->user?->name ?: $shipment->company?->name,
            'company' => $shipment->company?->name,
            'warehouse' => $shipment->warehouse?->name,
            'warehouse_id' => $shipment->warehouse_id,
            'amount' => (float) $shipment->total_amount,
            'items_count' => $shipment->items->count(),
            'weight' => $breakdown['weight'],
            'weightless_items' => $breakdown['missing'],

            // Статусы заказов — главный сигнал «можно ли вообще везти».
            // Заказ в «Ожидается оплата» склад не отгружает, и узнать об этом
            // надо до того, как заявка уйдёт перевозчику.
            'orders' => $relatedOrders->map(static fn (Order $order): array => [
                'number' => $order->erp_number ?: $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'status_color' => $order->status->color(),
                'delivery_method' => $order->delivery_method->value,
                'delivery_method_label' => $order->delivery_method->label(),
                'delivery_address' => $order->delivery_address,
            ])->values()->all(),
            'order_statuses' => $this->orderStatusSummary($relatedOrders),

            // Сведённые способ и адрес: если по всем заказам они совпали —
            // показываем одной строкой, иначе кладовщик разберётся по списку заказов.
            'delivery_method_label' => $this->singleValue($relatedOrders, static fn (Order $o): string => $o->delivery_method->label()),
            'delivery_address' => $this->singleValue($relatedOrders, fn (Order $o): ?string => $o->delivery_address),
            'delivery_kind' => $this->deliveryKind($relatedOrders),

            // Расходный ордер: по нему видно, собран ли груз физически.
            'goods_issue' => $goodsIssue === null ? null : [
                'number' => $goodsIssue->number,
                'status' => $goodsIssue->status,
                'status_label' => $goodsIssue->status_label,
                'status_color' => $goodsIssue->status_color,
                'is_stale' => $goodsIssue->is_stale,
                'is_shipped' => $goodsIssue->status === GoodsIssue::STATUS_SHIPPED,
                'packages_count' => (int) $goodsIssue->packages_count,
                'delivery_type_label' => $goodsIssue->delivery_type_label,
                'delivery_address' => $goodsIssue->delivery_address,
                'url' => route('wms.goods-issues.show', $goodsIssue),
            ],

            // Прошлая отправка — только отменённая или провалившаяся: активные
            // держат реализацию и в список вообще не попадают.
            'previous_delivery' => $previous[$shipment->getKey()] ?? null,
            'hidden' => $hidden[$shipment->getKey()] ?? null,
        ];
    }

    /**
     * Уникальные статусы заказов со счётчиком — для бейджей в строке.
     *
     * @param  Collection<int, Order>  $orders
     * @return list<array<string, mixed>>
     */
    private function orderStatusSummary(Collection $orders): array
    {
        return $orders
            ->groupBy(static fn (Order $order): string => $order->status->value)
            ->map(static fn (Collection $group, string $status): array => [
                'value' => $status,
                'label' => $group->first()->status->label(),
                'color' => $group->first()->status->color(),
                'count' => $group->count(),
            ])
            ->values()
            ->all();
    }

    // ─────────────────────────── Пакетная загрузка ───────────────────────────

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return Collection<int, string>
     */
    private function orderUuids(Collection $shipments): Collection
    {
        return $shipments
            ->flatMap(fn (Shipment $shipment): array => $shipment->items->pluck('order_uuid')->all())
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Заказы за реализациями — одним запросом по всем order_uuid.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<string, Order>
     */
    private function loadOrders(Collection $shipments): array
    {
        $uuids = $this->orderUuids($shipments);

        if ($uuids->isEmpty()) {
            return [];
        }

        return Order::withoutGlobalScopes()
            ->whereIn('uuid', $uuids)
            ->get(['id', 'uuid', 'number', 'erp_number', 'status', 'delivery_method', 'delivery_address'])
            ->keyBy('uuid')
            ->all();
    }

    /**
     * Расходные ордера, связанные с теми же заказами.
     *
     * Прямой связи «реализация → расходный ордер» в 1С нет: оба документа
     * ссылаются на заказ, через него их и сводим. Если ордеров по заказу
     * несколько, берём последний — он и отражает текущее состояние сборки.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<string, GoodsIssue>
     */
    private function loadGoodsIssues(Collection $shipments): array
    {
        $uuids = $this->orderUuids($shipments);

        if ($uuids->isEmpty()) {
            return [];
        }

        $links = DB::table('goods_issue_items')
            ->whereIn('order_uuid', $uuids)
            ->select('order_uuid', 'goods_issue_id')
            ->distinct()
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $issues = GoodsIssue::query()
            ->whereIn('id', $links->pluck('goods_issue_id')->unique())
            ->get()
            ->keyBy('id');

        $byOrder = [];

        foreach ($links as $link) {
            $issue = $issues->get($link->goods_issue_id);

            if ($issue === null) {
                continue;
            }

            $current = $byOrder[$link->order_uuid] ?? null;

            if ($current === null || ($issue->date?->gt($current->date) ?? false)) {
                $byOrder[$link->order_uuid] = $issue;
            }
        }

        return $byOrder;
    }

    /**
     * Отменённые и провалившиеся отправки, в которых реализация уже побывала.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<int, array<string, mixed>>
     */
    private function loadPreviousDeliveries(Collection $shipments): array
    {
        $rows = DB::table('delivery_shipment_documents')
            ->join('delivery_shipments', 'delivery_shipments.id', '=', 'delivery_shipment_documents.delivery_shipment_id')
            ->whereIn('delivery_shipment_documents.shipment_id', $shipments->map->getKey()->all())
            ->whereNull('delivery_shipments.deleted_at')
            ->whereIn('delivery_shipments.status', [
                DeliveryShipmentStatus::CANCELLED->value,
                DeliveryShipmentStatus::FAILED->value,
            ])
            ->orderByDesc('delivery_shipments.id')
            ->get([
                'delivery_shipment_documents.shipment_id',
                'delivery_shipments.id as delivery_id',
                'delivery_shipments.number',
                'delivery_shipments.status',
                'delivery_shipments.provider_key',
            ]);

        $previous = [];

        foreach ($rows as $row) {
            // Первая строка — самая свежая отправка: запрос отсортирован по id desc.
            if (isset($previous[$row->shipment_id])) {
                continue;
            }

            $status = DeliveryShipmentStatus::from($row->status);

            $previous[(int) $row->shipment_id] = [
                'number' => $row->number,
                'status_label' => $status->label(),
                'status_color' => $status->color(),
                'provider_key' => $row->provider_key,
                'url' => route('wms.deliveries.show', $row->delivery_id),
            ];
        }

        return $previous;
    }

    /**
     * Пометки «скрыто складом».
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<int, array<string, mixed>>
     */
    private function loadHidden(Collection $shipments): array
    {
        return DB::table('delivery_hidden_shipments')
            ->leftJoin('users', 'users.id', '=', 'delivery_hidden_shipments.hidden_by')
            ->whereIn('delivery_hidden_shipments.shipment_id', $shipments->map->getKey()->all())
            ->get([
                'delivery_hidden_shipments.shipment_id',
                'delivery_hidden_shipments.reason',
                'delivery_hidden_shipments.created_at',
                'users.name as hidden_by_name',
            ])
            ->mapWithKeys(static fn ($row): array => [(int) $row->shipment_id => [
                'reason' => $row->reason,
                'by' => $row->hidden_by_name,
                'at' => $row->created_at,
            ]])
            ->all();
    }

    // ─────────────────────────── Хелперы ───────────────────────────

    /**
     * Способ доставки реализации целиком.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function deliveryKind(Collection $orders): string
    {
        if ($orders->isEmpty()) {
            return self::KIND_UNKNOWN;
        }

        $methods = $orders->map(static fn (Order $order): string => $order->delivery_method->value)->unique();

        if ($methods->count() > 1) {
            return self::KIND_MIXED;
        }

        return $methods->first() === DeliveryMethod::PICKUP->value
            ? self::KIND_PICKUP
            : self::KIND_DELIVERY;
    }

    /**
     * Значение, если оно одинаково у всех заказов; иначе null.
     *
     * @param  Collection<int, Order>  $orders
     * @param  callable(Order): ?string  $extract
     */
    private function singleValue(Collection $orders, callable $extract): ?string
    {
        $values = $orders->map($extract)->filter()->unique()->values();

        return $values->count() === 1 ? (string) $values->first() : null;
    }
}
