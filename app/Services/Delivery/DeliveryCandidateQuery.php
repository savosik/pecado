<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Models\Delivery\HiddenShipment;
use App\Models\GoodsIssue;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Отбор реализаций-кандидатов на отправку.
 *
 * Один и тот же отбор нужен двум местам: разделу «Реализации к доставке» с полным
 * набором фильтров и компактному поиску в форме создания отправки. Держать его
 * в контроллерах означало бы две расходящиеся копии правила «что вообще можно везти».
 */
class DeliveryCandidateQuery
{
    public function __construct(private readonly DeliveryShipmentBuilder $builder) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Shipment>
     */
    public function build(array $filters = [], ?int $exceptDeliveryId = null): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $locked = $this->builder->lockedShipmentIds($exceptDeliveryId);
        $hidden = $this->hiddenShipmentIds();
        $showHidden = (bool) ($filters['show_hidden'] ?? false);

        return Shipment::query()
            // Реализации активных отправок кандидатами быть не могут по определению.
            ->whereNotIn('id', $locked)
            // Скрытые показываются только по явной просьбе — иначе кнопка «убрать»
            // не имела бы смысла.
            ->when(
                $showHidden,
                fn (Builder $q) => $q->whereIn('id', $hidden),
                fn (Builder $q) => $q->whereNotIn('id', $hidden),
            )
            ->when($filters['user_id'] ?? null, fn (Builder $q, $userId) => $q->where('user_id', (int) $userId))
            ->when($this->ids($filters, 'client_ids'), fn (Builder $q, array $ids) => $q->whereIn('user_id', $ids))
            ->when($this->ids($filters, 'warehouse_ids'), fn (Builder $q, array $ids) => $q->whereIn('warehouse_id', $ids))
            ->when($this->ids($filters, 'shipment_ids'), fn (Builder $q, array $ids) => $q->whereIn('id', $ids))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = "%{$search}%";
                $q->where(fn (Builder $inner) => $inner
                    ->where('erp_number', 'like', $like)
                    ->orWhere('number', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('erp_name', 'like', $like))
                    ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like)));
            })
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('date', '<=', $to))
            ->when($filters['amount_from'] ?? null, fn (Builder $q, $from) => $q->where('total_amount', '>=', (float) $from))
            ->when($filters['amount_to'] ?? null, fn (Builder $q, $to) => $q->where('total_amount', '<=', (float) $to))
            // Статусы заказов и расходных ордеров живут в связанных таблицах, но
            // фильтровать по ним надо до потолка выборки — иначе «покажи всё готовое
            // к отгрузке» упрётся в первые загруженные строки.
            ->when($this->strings($filters, 'order_statuses'), fn (Builder $q, array $statuses) => $q
                ->whereHas('items', fn (Builder $i) => $i->whereIn(
                    'order_uuid',
                    Order::withoutGlobalScopes()->whereIn('status', $statuses)->select('uuid'),
                )))
            ->when($this->strings($filters, 'goods_issue_statuses'), fn (Builder $q, array $statuses) => $q
                ->whereHas('items', fn (Builder $i) => $i->whereIn(
                    'order_uuid',
                    DB::table('goods_issue_items')
                        ->join('goods_issues', 'goods_issues.id', '=', 'goods_issue_items.goods_issue_id')
                        ->whereIn('goods_issues.status', $statuses)
                        ->whereNotNull('goods_issue_items.order_uuid')
                        ->select('goods_issue_items.order_uuid'),
                )));
    }

    /**
     * Связи, без которых презентер сходит в базу по каждой строке.
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public function withRelations(Builder $query): Builder
    {
        return $query->with([
            'user:id,name,erp_name',
            'company:id,name',
            'warehouse:id,name',
            'items.product:id,name,weight_gross,weight_net',
        ]);
    }

    /**
     * @return list<int>
     */
    public function hiddenShipmentIds(): array
    {
        return HiddenShipment::query()
            ->pluck('shipment_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Сколько скрытых реализаций можно показать по флагу «показать скрытые».
     */
    public function hiddenCount(?int $exceptDeliveryId = null): int
    {
        return Shipment::query()
            ->whereIn('id', $this->hiddenShipmentIds())
            ->whereNotIn('id', $this->builder->lockedShipmentIds($exceptDeliveryId))
            ->count();
    }

    /**
     * Справочники для панели фильтров.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return [
            'orderStatuses' => collect(OrderStatus::cases())
                ->map(static fn (OrderStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'color' => $status->color(),
                ])->all(),
            'goodsIssueStatuses' => collect(GoodsIssue::STATUSES)
                ->map(static fn (string $status): array => [
                    'value' => $status,
                    'label' => GoodsIssue::STATUS_LABELS[$status],
                    'color' => GoodsIssue::STATUS_COLORS[$status],
                ])->all(),
            'deliveryKinds' => [
                ['value' => AvailableShipmentsPresenter::KIND_DELIVERY, 'label' => 'Доставка'],
                ['value' => AvailableShipmentsPresenter::KIND_PICKUP, 'label' => 'Самовывоз'],
                ['value' => AvailableShipmentsPresenter::KIND_MIXED, 'label' => 'Способы различаются'],
                ['value' => AvailableShipmentsPresenter::KIND_UNKNOWN, 'label' => 'Способ неизвестен'],
            ],
            'warehouses' => Warehouse::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Warehouse $warehouse): array => [
                    'value' => (string) $warehouse->id,
                    'label' => $warehouse->name,
                ])->all(),
            'groupBy' => [
                ['value' => 'client', 'label' => 'По клиенту'],
                ['value' => 'address', 'label' => 'По адресу'],
                ['value' => 'date', 'label' => 'По дате'],
                ['value' => 'kind', 'label' => 'По способу доставки'],
            ],
            'sorts' => [
                ['value' => 'date_desc', 'label' => 'Сначала свежие'],
                ['value' => 'date_asc', 'label' => 'Сначала старые'],
                ['value' => 'amount_desc', 'label' => 'По сумме'],
                ['value' => 'weight_desc', 'label' => 'По весу'],
                ['value' => 'number_asc', 'label' => 'По номеру'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function ids(array $filters, string $key): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): int => (int) $value,
            (array) ($filters[$key] ?? []),
        )));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function strings(array $filters, string $key): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($filters[$key] ?? []),
        ), static fn (string $value): bool => $value !== ''));
    }
}
