<?php

namespace App\Services\Pickup;

use App\Enums\DeliveryMethod;
use App\Enums\OrderFulfilmentStage as Stage;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\Order;
use App\Models\Pickup\PickupHandover;
use App\Models\User;
use App\Services\Warehouse\WarehouseSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Стадия исполнения заказа: резерв → на складе → собирается → собран → выдан (pick-03).
 *
 * Правила выведены из живых данных (см. эпик pick-00):
 *  - самовывозные ордера переходят в `shipped` сразу после сборки, до приезда курьера, поэтому
 *    «собран, ждёт выдачи» = `shipped` без выдачи, а всё до `shipped` — «собирается»;
 *  - `shipped` обратим, поэтому стадия нигде не хранится;
 *  - способ доставки и клиент берутся с заказа, связь ордера с заказом — только по `order_uuid`
 *    (`order_id` в строках пуст у четверти записей).
 *
 * Пакетно: три запроса на любой список заказов.
 */
class OrderFulfilmentResolver
{
    public function __construct(private readonly WarehouseSchedule $schedule) {}

    /**
     * @param  iterable<Order>  $orders
     * @return array<int, array<string, mixed>> order_id → представление стадии
     */
    public function forOrders(iterable $orders): array
    {
        $orders = collect($orders)->filter(fn ($o) => $o instanceof Order)->values();
        if ($orders->isEmpty()) {
            return [];
        }

        $uuids = $orders->pluck('uuid')->filter()->unique()->values();

        // 1. Какие ордера собираются по каким заказам (строки ордера).
        $links = $uuids->isEmpty() ? collect() : GoodsIssueItem::query()
            ->whereIn('order_uuid', $uuids)
            ->select(['goods_issue_id', 'order_uuid'])
            ->distinct()
            ->get();

        // 2. Сами ордера (удалённые в 1С отсекает SoftDeletes).
        $issues = $links->isEmpty() ? collect() : GoodsIssue::query()
            ->whereIn('id', $links->pluck('goods_issue_id')->unique())
            ->get(['id', 'uuid', 'number', 'status', 'status_changed_at', 'packages_count', 'created_at'])
            ->keyBy('id');

        // 3. Действующие выдачи с именем кладовщика.
        $handovers = $issues->isEmpty() ? collect() : PickupHandover::query()
            ->active()
            ->whereIn('goods_issue_id', $issues->keys())
            ->with('issuer:id,name')
            ->get()
            ->keyBy('goods_issue_id');

        $issueIdsByOrder = $links->groupBy('order_uuid')->map(fn (Collection $rows) => $rows->pluck('goods_issue_id')->unique());
        $ordersPerIssue = $links->groupBy('goods_issue_id')->map(fn (Collection $rows) => $rows->pluck('order_uuid')->unique()->count());

        $result = [];
        foreach ($orders as $order) {
            $own = ($issueIdsByOrder[$order->uuid] ?? collect())
                ->map(fn ($id) => $issues->get($id))
                ->filter()
                ->values();

            $result[$order->id] = $this->view($order, $own, $handovers, $ordersPerIssue);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function forOrder(Order $order): array
    {
        return $this->forOrders([$order])[$order->id];
    }

    /**
     * Стадия одного расходного ордера.
     *
     * Выдача важнее статуса; «ждёт выдачи» — только самовывоз, собранный после даты отсечения,
     * иначе в очереди всплыла бы вся история до запуска.
     */
    public function issueStage(GoodsIssue $issue, bool $isPickup, bool $handedOver): Stage
    {
        if ($handedOver) {
            return Stage::HANDED_OVER;
        }

        if ($issue->status !== GoodsIssue::STATUS_SHIPPED) {
            return Stage::PICKING;
        }

        return $isPickup && $this->afterCutoff($issue->status_changed_at) ? Stage::READY : Stage::SHIPPED;
    }

    /** Момент, с которого собранные ордера считаются ожидающими выдачи (null — отсечения нет). */
    public function handoverSince(): ?Carbon
    {
        $since = config('pickup.handover_since');

        return $since ? Carbon::parse($since, $this->schedule->timezone()) : null;
    }

    /**
     * Расходные ордера клиента, готовые к выдаче: общий источник для кабинета, пропуска и API.
     * Клиент определяется от заказа, а не от шапки ордера (она пуста у 40 % документов).
     *
     * @return Collection<int, GoodsIssue> с подгруженными `pickupOrders` (заказы клиента в этом ордере)
     */
    public function readyForUser(User $user): Collection
    {
        return $this->issuesForUser($user)
            ->filter(fn (GoodsIssue $gi) => $this->issueStage($gi, true, $gi->activeHandover !== null) === Stage::READY)
            ->values();
    }

    /**
     * Ордера клиента по самовывозным заказам за последние 60 дней.
     *
     * @return Collection<int, GoodsIssue>
     */
    public function issuesForUser(User $user): Collection
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('delivery_method', DeliveryMethod::PICKUP->value)
            ->where('created_at', '>=', now()->subDays(60))
            ->get(['id', 'uuid', 'number', 'erp_number', 'company_id', 'total_amount', 'created_at'])
            ->keyBy('uuid');

        if ($orders->isEmpty()) {
            return collect();
        }

        $links = GoodsIssueItem::query()
            ->whereIn('order_uuid', $orders->keys())
            ->select(['goods_issue_id', 'order_uuid'])
            ->distinct()
            ->get()
            ->groupBy('goods_issue_id');

        return GoodsIssue::query()
            ->whereIn('id', $links->keys())
            ->with(['activeHandover.issuer:id,name'])
            ->orderBy('status_changed_at')
            ->get()
            ->each(fn (GoodsIssue $gi) => $gi->setRelation(
                'pickupOrders',
                $links[$gi->id]->map(fn ($row) => $orders->get($row->order_uuid))->filter()->values(),
            ));
    }

    /**
     * @param  Collection<int, GoodsIssue>  $issues
     * @param  Collection<int, PickupHandover>  $handovers
     * @param  Collection<int, int>  $ordersPerIssue
     * @return array<string, mixed>
     */
    private function view(Order $order, Collection $issues, Collection $handovers, Collection $ordersPerIssue): array
    {
        $isPickup = $order->delivery_method === DeliveryMethod::PICKUP;

        $rows = $issues->map(function (GoodsIssue $gi) use ($isPickup, $handovers, $ordersPerIssue) {
            $handover = $handovers->get($gi->id);
            $stage = $this->issueStage($gi, $isPickup, $handover !== null);

            return [
                'id' => $gi->id,
                'number' => $gi->number,
                'status' => $gi->status,
                'status_label' => GoodsIssue::STATUS_LABELS[$gi->status] ?? $gi->status,
                'stage' => $stage->value,
                'stage_label' => $stage->label(),
                'packages_count' => (int) $gi->packages_count,
                'shared_with_orders' => max(0, (int) ($ordersPerIssue[$gi->id] ?? 1) - 1),
                'ready_since' => $stage === Stage::READY ? $gi->status_changed_at?->toIso8601String() : null,
                'handed_at' => $handover?->issued_at?->toIso8601String(),
                'handed_by' => $handover?->issuer?->name,
                'recipient_name' => $handover?->recipient_name,
                'needs_review' => (bool) $handover?->needs_review,
            ];
        })->values();

        $stage = $this->orderStage($order, $rows);
        $handed = $rows->where('stage', Stage::HANDED_OVER->value);
        $done = $rows->whereIn('stage', [Stage::READY->value, Stage::HANDED_OVER->value, Stage::SHIPPED->value]);

        return [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'color' => $stage->color(),
            'hint' => $stage->hint(),
            'step' => $stage->step(),
            'is_pickup' => $isPickup,
            'is_partial' => $rows->count() > 1 && $rows->pluck('stage')->unique()->count() > 1,
            'goods_issues' => $rows->all(),
            'packages_total' => (int) $rows->sum('packages_count'),
            'packages_handed' => (int) $handed->sum('packages_count'),
            'issues_total' => $rows->count(),
            'issues_done' => $done->count(),
            'ready_since' => $stage === Stage::READY ? $rows->pluck('ready_since')->filter()->max() : null,
            'handed_at' => $stage === Stage::HANDED_OVER ? $rows->pluck('handed_at')->filter()->max() : null,
            'promise' => $this->promise($order, $stage, $issues),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function orderStage(Order $order, Collection $rows): Stage
    {
        if ($order->reserve) {
            return Stage::RESERVED;
        }

        if ($rows->isEmpty()) {
            $waiting = $order->type === OrderType::ORDER
                && $order->status === OrderStatus::READY_FOR_SHIPMENT
                && ! $order->trashed();

            return $waiting ? Stage::SENT_TO_WAREHOUSE : Stage::NONE;
        }

        // Стадия заказа — минимальная по его ордерам: «собран» только когда собрано всё.
        $rank = [Stage::PICKING->value => 1, Stage::READY->value => 2, Stage::SHIPPED->value => 2, Stage::HANDED_OVER->value => 3];
        $lowest = $rows->sortBy(fn (array $row) => $rank[$row['stage']] ?? 0)->first();

        return Stage::from($lowest['stage']);
    }

    /**
     * Обещание времени сборки — пока заказ на складе или собирается.
     *
     * @param  Collection<int, GoodsIssue>  $issues
     * @return array<string, mixed>|null
     */
    private function promise(Order $order, Stage $stage, Collection $issues): ?array
    {
        if (! in_array($stage, [Stage::SENT_TO_WAREHOUSE, Stage::PICKING], true)) {
            return null;
        }

        $base = $issues->pluck('created_at')->filter()->min() ?? $order->updated_at ?? $order->created_at;
        $promised = $base instanceof CarbonInterface ? $this->schedule->promisedReadyAt($base) : null;
        if ($promised === null) {
            return null;
        }

        $now = now();

        return [
            'promised_at' => $promised->toIso8601String(),
            'text' => 'Ожидаем к ~'.$promised->format($promised->isSameDay($now) ? 'H:i' : 'd.m H:i'),
            'is_overdue' => $promised->lt($now),
        ];
    }

    private function afterCutoff(?CarbonInterface $changedAt): bool
    {
        $since = $this->handoverSince();

        return $since === null || ($changedAt !== null && $changedAt->gte($since));
    }
}
