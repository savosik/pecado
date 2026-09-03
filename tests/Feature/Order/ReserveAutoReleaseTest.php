<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-09): авто-снятие просроченных резервов
 * и уведомления через матрицу (orders.reserve_expiring / reserve_released).
 */
class ReserveAutoReleaseTest extends TestCase
{
    use EnablesClientNotifications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'order_reserve.enabled' => true,
            'order_reserve.expiring_notice_hours' => 3,
            'mail_stream.enabled' => true,
        ]);
        Queue::fake([PublishOrderToErpJob::class]);
    }

    /** Клиент с персональным менеджером — у письма потока обязателен автор. */
    private function clientWithManager(): User
    {
        $manager = User::factory()->create();
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);

        return User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    private function reserveOrder(User $user, $until): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => $until,
            'erp_number' => 'ЗК-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
    }

    #[Test]
    public function expired_reserve_is_released_and_published(): void
    {
        $user = User::factory()->create();
        $expired = $this->reserveOrder($user, now()->subMinutes(10));
        $alive = $this->reserveOrder($user, now()->addHours(10));

        $this->artisan('reserve:release-expired')->assertSuccessful();

        $expired = Order::withTrashed()->find($expired->id);
        $this->assertTrue($expired->trashed());
        $this->assertFalse($expired->reserve);
        $this->assertSame(OrderStatus::CLOSED, $expired->status);

        $this->assertFalse(Order::find($alive->id)->trashed());
        $this->assertTrue(Order::find($alive->id)->reserve, 'живой резерв не тронут');

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($expired) {
            return $job->payload['event'] === 'order.deleted'
                && $job->payload['uuid'] === $expired->uuid
                && $job->payload['reason'] === 'reserve_expired';
        });

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $expired->id,
            'new_status' => OrderStatus::CLOSED->value,
            'comment' => 'Резерв истёк — заказ снят автоматически',
        ]);
    }

    #[Test]
    public function repeated_run_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->reserveOrder($user, now()->subMinutes(10));

        $this->artisan('reserve:release-expired')->assertSuccessful();
        $this->artisan('reserve:release-expired')->assertSuccessful();

        Queue::assertPushed(PublishOrderToErpJob::class, 1);
    }

    #[Test]
    public function released_notification_is_captured_for_subscribed_client(): void
    {
        $user = $this->clientWithManager();
        $this->enableNotificationsFor($user, ['orders.reserve_released', 'orders.reserve_expiring']);
        $order = $this->reserveOrder($user, now()->subMinutes(5));

        $this->artisan('reserve:release-expired')->assertSuccessful();

        $this->assertDatabaseHas('crm_emails', [
            'origin_event' => 'orders.reserve_released',
        ]);
    }

    #[Test]
    public function expiring_notification_is_captured_once(): void
    {
        $user = $this->clientWithManager();
        $this->enableNotificationsFor($user, ['orders.reserve_expiring']);
        $this->reserveOrder($user, now()->addHours(2));

        $this->artisan('reserve:release-expired')->assertSuccessful();
        $this->artisan('reserve:release-expired')->assertSuccessful();

        $this->assertSame(1, \App\Models\CrmEmail::query()
            ->where('origin_event', 'orders.reserve_expiring')
            ->count(), 'повторный прогон не дублирует предупреждение');
    }

    #[Test]
    public function command_works_even_when_feature_flag_is_off(): void
    {
        // Аварийное выключение рубильника не должно подвешивать висящие резервы
        config(['order_reserve.enabled' => false]);
        $user = User::factory()->create();
        $expired = $this->reserveOrder($user, now()->subMinutes(10));

        $this->artisan('reserve:release-expired')->assertSuccessful();

        $this->assertTrue(Order::withTrashed()->find($expired->id)->trashed());
    }
}
