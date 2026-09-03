<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-04): отмена заказа клиентом из кабинета.
 * Весь контур за рубильником order_reserve.enabled.
 */
class ClientOrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true]);
    }

    #[Test]
    public function owner_cancels_early_status_order(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/cancel")
            ->assertOk();

        $order = Order::withTrashed()->find($order->id);
        $this->assertTrue($order->trashed(), 'заказ soft-deleted');
        $this->assertSame(OrderStatus::CLOSED, $order->status);

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($order) {
            return $job->payload['event'] === 'order.deleted'
                && $job->payload['uuid'] === $order->uuid
                && $job->payload['reason'] === 'client_cancelled';
        });
    }

    #[Test]
    public function reserve_order_is_cancellable_despite_ready_for_shipment_status(): void
    {
        // Резервный заказ приезжает из 1С как ready_for_shipment — своего статуса
        // у резерва нет, окно резерва и есть окно отмены
        Queue::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/cancel")
            ->assertOk();

        $order = Order::withTrashed()->find($order->id);
        $this->assertTrue($order->trashed());
        $this->assertFalse($order->reserve, 'признак резерва снят при отмене');
    }

    #[Test]
    public function late_status_order_is_rejected_with_race_friendly_message(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::SHIPPING,
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'сборку'));

        $this->assertFalse(Order::withTrashed()->find($order->id)->trashed());
        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function foreign_order_is_forbidden(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $owner->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);

        $this->actingAs($stranger)
            ->postJson("/cabinet/orders/{$order->id}/cancel")
            ->assertForbidden();

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function disabled_feature_flag_hides_endpoint_and_button(): void
    {
        config(['order_reserve.enabled' => false]);
        Queue::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/cancel")
            ->assertNotFound();

        $this->actingAs($user)
            ->get("/cabinet/orders/{$order->id}")
            ->assertInertia(fn ($page) => $page->where('order.can_cancel', false));

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function show_page_exposes_can_cancel_for_early_status(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);

        $this->actingAs($user)
            ->get("/cabinet/orders/{$order->id}")
            ->assertInertia(fn ($page) => $page->where('order.can_cancel', true));

        $shipped = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::SHIPPING,
        ]);

        $this->actingAs($user)
            ->get("/cabinet/orders/{$shipped->id}")
            ->assertInertia(fn ($page) => $page->where('order.can_cancel', false));
    }

    #[Test]
    public function cancellation_is_written_to_status_history_with_client_marker(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'new_status' => OrderStatus::CLOSED->value,
            'user_id' => $user->id,
            'comment' => 'Отменён клиентом из кабинета',
        ]);
    }
}
