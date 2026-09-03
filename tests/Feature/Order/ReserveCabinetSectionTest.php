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
 * v16.9.0, режим «Заказы в резерве» (res-07): раздел кабинета и подтверждение
 * отгрузки. Весь контур за рубильником order_reserve.enabled.
 */
class ReserveCabinetSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true]);
    }

    private function reserveOrder(User $user, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
        ], $attrs));
    }

    #[Test]
    public function index_lists_only_own_active_reserves_ordered_by_expiry(): void
    {
        $user = User::factory()->create();
        $later = $this->reserveOrder($user, ['reserved_until' => now()->addHours(20)]);
        $sooner = $this->reserveOrder($user, ['reserved_until' => now()->addHours(2)]);
        $this->reserveOrder(User::factory()->create()); // чужой
        Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::READY_FOR_PROVISION]); // не резерв

        $this->actingAs($user)
            ->get('/cabinet/reserves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/Reserves/Index')
                ->has('reserves', 2)
                ->where('reserves.0.id', $sooner->id)
                ->where('reserves.1.id', $later->id));
    }

    #[Test]
    public function index_is_hidden_behind_feature_flag(): void
    {
        config(['order_reserve.enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/cabinet/reserves')->assertNotFound();
    }

    #[Test]
    public function confirm_publishes_order_confirmed_and_releases_local_flag(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $order = $this->reserveOrder($user);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/confirm-reserve")
            ->assertOk();

        $this->assertFalse($order->refresh()->reserve, 'локально признак снят, заказ ушёл из раздела');

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($order) {
            return $job->payload['event'] === 'order.confirmed'
                && $job->payload['uuid'] === $order->uuid
                && ! empty($job->payload['confirmed_at']);
        });
    }

    #[Test]
    public function confirm_rejects_non_reserve_order_race_friendly(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => false,
        ]);

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/confirm-reserve")
            ->assertStatus(422);

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function confirm_forbidden_for_foreign_order(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $order = $this->reserveOrder(User::factory()->create());

        $this->actingAs($user)
            ->postJson("/cabinet/orders/{$order->id}/confirm-reserve")
            ->assertForbidden();

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function shared_props_expose_reserve_count_only_for_participants(): void
    {
        $participant = User::factory()->create(['reserve_allowed' => true]);
        $this->reserveOrder($participant);
        $this->reserveOrder($participant);

        $this->actingAs($participant)
            ->get('/cabinet/orders')
            ->assertInertia(fn ($page) => $page
                ->where('config.reserves_enabled', true)
                ->where('config.reserve_count', 2));

        $outsider = User::factory()->create(['reserve_allowed' => false]);

        $this->actingAs($outsider)
            ->get('/cabinet/orders')
            ->assertInertia(fn ($page) => $page
                ->where('config.reserves_enabled', false)
                ->where('config.reserve_count', 0));
    }

    #[Test]
    public function order_show_exposes_reserve_banner_fields(): void
    {
        $user = User::factory()->create();
        $order = $this->reserveOrder($user);

        $this->actingAs($user)
            ->get("/cabinet/orders/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('order.reserve', true)
                ->where('order.can_cancel', true)
                ->whereNot('order.reserved_until', null));
    }
}
