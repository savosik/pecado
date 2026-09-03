<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-10): самообслуживание резервов через
 * клиентский API — зеркало кнопок кабинета поверх общего ClientOrderActions.
 */
class ClientApiReservesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true]);
        Queue::fake([PublishOrderToErpJob::class]);

        $this->user = User::factory()->create(['reserve_allowed' => true]);
        $this->token = ApiToken::create([
            'user_id' => $this->user->id,
            'name' => 'test',
            'is_active' => true,
        ]);
    }

    private function url(string $path): string
    {
        return "/api/client-api/{$this->token->token}{$path}";
    }

    private function reserveOrder(?User $user = null): Order
    {
        $order = Order::factory()->create([
            'user_id' => ($user ?? $this->user)->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'total_amount' => 3000,
        ]);

        $product = Product::factory()->create(['external_id' => 'ext-'.$order->id]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name,
            'line_number' => 1, 'quantity' => 3, 'price' => 1000,
            'final_price' => 1000, 'subtotal' => 3000,
        ]);

        return $order;
    }

    #[Test]
    public function reserves_list_returns_own_reserves_with_deadline_and_items(): void
    {
        $order = $this->reserveOrder();
        $this->reserveOrder(User::factory()->create(['reserve_allowed' => true])); // чужой

        $this->getJson($this->url('/reserves'))
            ->assertOk()
            ->assertJsonCount(1, 'reserves')
            ->assertJsonPath('reserves.0.order_id', $order->id)
            ->assertJsonPath('reserves.0.items.0.quantity', 3)
            ->assertJsonPath('reserves.0.total_amount', 3000);
    }

    #[Test]
    public function non_participant_gets_machine_readable_403(): void
    {
        $this->user->update(['reserve_allowed' => false]);

        $this->getJson($this->url('/reserves'))
            ->assertForbidden()
            ->assertJsonPath('code', 'reserve_unavailable');
    }

    #[Test]
    public function confirm_sends_order_confirmed(): void
    {
        $order = $this->reserveOrder();

        $this->postJson($this->url("/reserves/{$order->id}/confirm"))
            ->assertOk();

        $this->assertFalse($order->refresh()->reserve);
        Queue::assertPushed(PublishOrderToErpJob::class, fn (PublishOrderToErpJob $job) => $job->payload['event'] === 'order.confirmed' && $job->payload['uuid'] === $order->uuid);
    }

    #[Test]
    public function items_update_decreases_and_rejects_increase_with_codes(): void
    {
        $order = $this->reserveOrder();
        $item = $order->items()->first();

        $this->postJson($this->url("/reserves/{$order->id}/items"), [
            'items' => [['item_id' => $item->id, 'quantity' => 5]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'increase_forbidden');

        $this->postJson($this->url("/reserves/{$order->id}/items"), [
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])
            ->assertOk()
            ->assertJsonPath('total_amount', 1000);

        $this->assertSame(1, (int) $item->refresh()->quantity);
        Queue::assertPushed(PublishOrderToErpJob::class, fn (PublishOrderToErpJob $job) => $job->payload['event'] === 'order.updated');
    }

    #[Test]
    public function cancel_releases_reserve_and_marks_api_channel(): void
    {
        $order = $this->reserveOrder();

        $this->postJson($this->url("/reserves/{$order->id}/cancel"))
            ->assertOk();

        $order = Order::withTrashed()->find($order->id);
        $this->assertTrue($order->trashed());
        $this->assertSame(OrderStatus::CLOSED, $order->status);

        Queue::assertPushed(PublishOrderToErpJob::class, fn (PublishOrderToErpJob $job) => $job->payload['event'] === 'order.deleted' && $job->payload['reason'] === 'client_cancelled');

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'comment' => 'Отменён клиентом через API',
        ]);
    }

    #[Test]
    public function race_and_missing_order_give_codes(): void
    {
        $plain = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => false,
        ]);

        $this->postJson($this->url("/reserves/{$plain->id}/confirm"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_reserved');

        $this->postJson($this->url('/reserves/999999/confirm'))
            ->assertNotFound()
            ->assertJsonPath('code', 'order_not_found');

        $foreign = $this->reserveOrder(User::factory()->create(['reserve_allowed' => true]));
        $this->postJson($this->url("/reserves/{$foreign->id}/confirm"))
            ->assertNotFound()
            ->assertJsonPath('code', 'order_not_found');
    }
}
