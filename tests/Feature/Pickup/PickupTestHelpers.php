<?php

namespace Tests\Feature\Pickup;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Company;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\GoodsIssueStatusHistory;
use App\Models\Order;
use App\Models\User;

/** Общие заготовки тестов самовывоза: клиент, самовывозный заказ, расходный ордер по заказам. */
trait PickupTestHelpers
{
    protected function pickupClient(): User
    {
        $user = User::factory()->create();
        Company::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    protected function pickupOrder(User $user, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'company_id' => $user->companies()->value('id'),
            'type' => OrderType::ORDER,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'delivery_method' => DeliveryMethod::PICKUP,
            'reserve' => false,
        ], $attrs));
    }

    /** @param Order|array<int, Order> $orders */
    protected function goodsIssueFor(Order|array $orders, string $status = GoodsIssue::STATUS_SHIPPED, array $attrs = []): GoodsIssue
    {
        $issue = GoodsIssue::factory()->create(array_merge([
            'status' => $status,
            'status_changed_at' => now()->subMinutes(10),
            'packages_count' => 2,
        ], $attrs));

        foreach ((array) (is_array($orders) ? $orders : [$orders]) as $i => $order) {
            GoodsIssueItem::factory()->create([
                'goods_issue_id' => $issue->id,
                'line_number' => $i + 1,
                'order_uuid' => $order->uuid,
                'order_id' => null, // как в бою: связь только по uuid
                'order_number' => $order->erp_number ?? $order->number,
            ]);
        }

        return $issue;
    }

    /** Смена статуса так, как её пишет маппер шины: статус + строка журнала. */
    protected function moveIssue(GoodsIssue $issue, string $to): void
    {
        $from = $issue->status;
        $issue->forceFill(['status' => $to, 'status_changed_at' => now()])->save();
        GoodsIssueStatusHistory::create([
            'goods_issue_id' => $issue->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_at' => now(),
            'source' => GoodsIssueStatusHistory::SOURCE_ERP,
        ]);
    }
}
