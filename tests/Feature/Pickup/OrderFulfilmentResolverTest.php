<?php

namespace Tests\Feature\Pickup;

use App\Enums\DeliveryMethod;
use App\Enums\OrderFulfilmentStage as Stage;
use App\Enums\OrderStatus;
use App\Models\GoodsIssue;
use App\Models\User;
use App\Services\Pickup\HandoverService;
use App\Services\Pickup\OrderFulfilmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-03: стадия исполнения заказа считается на лету из расходных ордеров и выдач. */
class OrderFulfilmentResolverTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    private OrderFulfilmentResolver $resolver;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.handover_since' => null]);
        $this->resolver = app(OrderFulfilmentResolver::class);
        $this->client = $this->pickupClient();
    }

    private function stage($order): string
    {
        return $this->resolver->forOrder($order->fresh())['stage'];
    }

    #[Test]
    public function reserved_order_is_reserved_even_with_technical_status(): void
    {
        $order = $this->pickupOrder($this->client, ['reserve' => true, 'reserved_until' => now()->addDay()]);

        $this->assertSame(Stage::RESERVED->value, $this->stage($order));
    }

    #[Test]
    public function order_without_goods_issue_is_sent_to_warehouse_with_promise(): void
    {
        $order = $this->pickupOrder($this->client);
        $view = $this->resolver->forOrder($order);

        $this->assertSame(Stage::SENT_TO_WAREHOUSE->value, $view['stage']);
        $this->assertNotNull($view['promise']);
        $this->assertStringStartsWith('Ожидаем к ~', $view['promise']['text']);
    }

    #[Test]
    public function closed_order_without_goods_issue_has_no_stage(): void
    {
        $order = $this->pickupOrder($this->client, ['status' => OrderStatus::CLOSED]);

        $this->assertSame(Stage::NONE->value, $this->stage($order));
    }

    #[Test]
    public function single_goods_issue_walks_through_stages(): void
    {
        $order = $this->pickupOrder($this->client);
        $issue = $this->goodsIssueFor($order, GoodsIssue::STATUS_TO_PICK);
        $this->assertSame(Stage::PICKING->value, $this->stage($order));

        $this->moveIssue($issue, GoodsIssue::STATUS_TO_SHIP);
        $this->assertSame(Stage::PICKING->value, $this->stage($order), '«к отгрузке» — ещё не собран: склад ставит «отгружен» сразу следом');

        $this->moveIssue($issue, GoodsIssue::STATUS_SHIPPED);
        $view = $this->resolver->forOrder($order->fresh());
        $this->assertSame(Stage::READY->value, $view['stage']);
        $this->assertNotNull($view['ready_since']);
        $this->assertSame(2, $view['packages_total']);

        app(HandoverService::class)->issue($issue, User::factory()->create(['name' => 'Кладовщик Петров']), 'manual');
        $view = $this->resolver->forOrder($order->fresh());
        $this->assertSame(Stage::HANDED_OVER->value, $view['stage']);
        $this->assertSame('Кладовщик Петров', $view['goods_issues'][0]['handed_by']);
        $this->assertSame(2, $view['packages_handed']);
    }

    #[Test]
    public function shipped_rollback_returns_order_to_picking(): void
    {
        $order = $this->pickupOrder($this->client);
        $issue = $this->goodsIssueFor($order);
        $this->assertSame(Stage::READY->value, $this->stage($order));

        $this->moveIssue($issue, GoodsIssue::STATUS_TO_PICK);

        $this->assertSame(Stage::PICKING->value, $this->stage($order));
    }

    #[Test]
    public function order_stage_is_the_lowest_of_its_goods_issues(): void
    {
        $order = $this->pickupOrder($this->client);
        $first = $this->goodsIssueFor($order);
        $this->goodsIssueFor($order, GoodsIssue::STATUS_TO_CHECK);

        $view = $this->resolver->forOrder($order);
        $this->assertSame(Stage::PICKING->value, $view['stage']);
        $this->assertTrue($view['is_partial']);
        $this->assertSame(1, $view['issues_done']);

        app(HandoverService::class)->issue($first, User::factory()->create(), 'manual');
        $this->assertSame(Stage::PICKING->value, $this->stage($order), 'выдан один из двух — заказ ещё собирается');
    }

    #[Test]
    public function goods_issue_shared_by_several_orders_marks_them_all(): void
    {
        $a = $this->pickupOrder($this->client);
        $b = $this->pickupOrder($this->client);
        $this->goodsIssueFor([$a, $b]);

        $views = $this->resolver->forOrders([$a, $b]);

        $this->assertSame(Stage::READY->value, $views[$a->id]['stage']);
        $this->assertSame(Stage::READY->value, $views[$b->id]['stage']);
        $this->assertSame(1, $views[$a->id]['goods_issues'][0]['shared_with_orders']);
    }

    #[Test]
    public function delivery_order_is_shipped_not_waiting_for_handover(): void
    {
        $order = $this->pickupOrder($this->client, ['delivery_method' => DeliveryMethod::DELIVERY]);
        $this->goodsIssueFor($order);

        $view = $this->resolver->forOrder($order);
        $this->assertSame(Stage::SHIPPED->value, $view['stage']);
        $this->assertFalse($view['is_pickup']);
    }

    #[Test]
    public function deleted_goods_issue_is_ignored(): void
    {
        $order = $this->pickupOrder($this->client);
        $this->goodsIssueFor($order)->delete();

        $this->assertSame(Stage::SENT_TO_WAREHOUSE->value, $this->stage($order));
    }

    #[Test]
    public function history_before_cutoff_is_not_waiting_for_handover(): void
    {
        config(['pickup.handover_since' => now()->subDay()->format('Y-m-d H:i')]);

        $old = $this->pickupOrder($this->client);
        $this->goodsIssueFor($old, GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDays(5)]);
        $fresh = $this->pickupOrder($this->client);
        $this->goodsIssueFor($fresh);

        $this->assertSame(Stage::SHIPPED->value, $this->stage($old));
        $this->assertSame(Stage::READY->value, $this->stage($fresh));
        $this->assertCount(1, $this->resolver->readyForUser($this->client));
    }

    #[Test]
    public function ready_for_user_takes_client_from_order_not_from_goods_issue_header(): void
    {
        $other = $this->pickupClient();
        $mine = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['user_id' => null, 'company_id' => null]);
        $this->goodsIssueFor($this->pickupOrder($other));
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_PICK);

        $ready = $this->resolver->readyForUser($this->client);

        $this->assertSame([$mine->id], $ready->pluck('id')->all());
        $this->assertCount(1, $ready->first()->pickupOrders);
    }

    #[Test]
    public function batch_resolution_does_not_query_per_order(): void
    {
        $orders = collect(range(1, 12))->map(function () {
            $order = $this->pickupOrder($this->client);
            $this->goodsIssueFor($order);

            return $order;
        });

        DB::enableQueryLog();
        $this->resolver->forOrders($orders);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries);
    }
}
