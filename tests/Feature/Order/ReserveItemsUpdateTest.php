<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-08): правка состава клиентом —
 * только уменьшение, полная замена состава в исходящем order.updated.
 */
class ReserveItemsUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true]);
        Queue::fake([PublishOrderToErpJob::class]);
    }

    /** @return array{0: Order, 1: OrderItem, 2: OrderItem} */
    private function reserveOrderWithTwoItems(User $user): array
    {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'items_version' => 4,
            'total_amount' => 3500,
        ]);

        $p1 = Product::factory()->create(['external_id' => 'ext-1']);
        $p2 = Product::factory()->create(['external_id' => 'ext-2']);

        $first = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p1->id, 'name' => $p1->name,
            'line_number' => 1, 'quantity' => 3, 'price' => 1000,
            'final_price' => 1000, 'subtotal' => 3000,
        ]);
        $second = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p2->id, 'name' => $p2->name,
            'line_number' => 2, 'quantity' => 1, 'price' => 500,
            'final_price' => 500, 'subtotal' => 500,
        ]);

        return [$order, $first, $second];
    }

    #[Test]
    public function decrease_and_remove_line_publishes_full_composition(): void
    {
        $user = User::factory()->create();
        [$order, $first, $second] = $this->reserveOrderWithTwoItems($user);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/reserve-items", [
                'items' => [['id' => $first->id, 'quantity' => 2]],
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame(2, (int) $first->refresh()->quantity);
        $this->assertNull(OrderItem::find($second->id), 'отсутствующая в целевом составе строка удалена');
        $this->assertSame(2000.0, (float) $order->total_amount);

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($order) {
            return $job->payload['event'] === 'order.updated'
                && $job->payload['uuid'] === $order->uuid
                && $job->payload['base_items_version'] === 4
                && count($job->payload['items']) === 1
                && $job->payload['items'][0]['quantity'] == 2;
        });

        $this->assertDatabaseHas('order_change_logs', [
            'order_id' => $order->id,
            'source' => 'client',
        ]);
    }

    #[Test]
    public function increase_is_rejected(): void
    {
        $user = User::factory()->create();
        [$order, $first] = $this->reserveOrderWithTwoItems($user);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/reserve-items", [
                'items' => [['id' => $first->id, 'quantity' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'только уменьшить'));

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function empty_target_composition_is_rejected(): void
    {
        $user = User::factory()->create();
        [$order] = $this->reserveOrderWithTwoItems($user);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/reserve-items", ['items' => []])
            ->assertStatus(422);

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function no_changes_is_rejected(): void
    {
        $user = User::factory()->create();
        [$order, $first, $second] = $this->reserveOrderWithTwoItems($user);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/reserve-items", [
                'items' => [
                    ['id' => $first->id, 'quantity' => 3],
                    ['id' => $second->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422);

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function non_reserve_order_and_foreign_order_are_rejected(): void
    {
        $user = User::factory()->create();
        $plain = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
            'reserve' => false,
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$plain->id}/reserve-items", [
                'items' => [['id' => 1, 'quantity' => 1]],
            ])
            ->assertStatus(422);

        [$foreign, $item] = $this->reserveOrderWithTwoItems(User::factory()->create());

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$foreign->id}/reserve-items", [
                'items' => [['id' => $item->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function feature_flag_hides_endpoint(): void
    {
        config(['order_reserve.enabled' => false]);
        $user = User::factory()->create();
        [$order, $first] = $this->reserveOrderWithTwoItems($user);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/reserve-items", [
                'items' => [['id' => $first->id, 'quantity' => 1]],
            ])
            ->assertNotFound();
    }
}
