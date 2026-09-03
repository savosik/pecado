<?php

namespace Tests\Feature\Crm;

use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-11): сводка злоупотреблений в CRM
 * и рычаг РОПа (точечное отключение, индивидуальное окно).
 */
class ReserveControlTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true, 'order_reserve.expired_share_alert' => 0.3]);
        Queue::fake([PublishOrderToErpJob::class]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
    }

    private function outcomeOrder(User $partner, string $outcome): Order
    {
        return Order::factory()->create([
            'user_id' => $partner->id,
            'status' => OrderStatus::CLOSED,
            'reserve' => false,
            'reserve_outcome' => $outcome,
        ]);
    }

    #[Test]
    public function summary_counts_outcomes_and_flags_red_zone(): void
    {
        $abuser = User::factory()->create(['reserve_allowed' => true, 'erp_name' => 'ООО Бросатель']);
        $this->outcomeOrder($abuser, 'confirmed');
        $this->outcomeOrder($abuser, 'expired');
        $this->outcomeOrder($abuser, 'expired');

        $good = User::factory()->create(['reserve_allowed' => true, 'erp_name' => 'ООО Надёжный']);
        $this->outcomeOrder($good, 'confirmed');
        Order::factory()->create([
            'user_id' => $good->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(5),
        ]);

        $this->actingAs($this->head)
            ->get('/crm/reserves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/Reserves/Index')
                ->has('partners', 2)
                // Сортировка: злоупотребляющий (67% сгоревших) — первым
                ->where('partners.0.name', 'ООО Бросатель')
                ->where('partners.0.expired', 2)
                ->where('partners.0.confirmed', 1)
                ->where('partners.0.expired_share', 0.67)
                ->where('partners.1.name', 'ООО Надёжный')
                ->where('partners.1.active', 1)
                ->where('partners.1.expired_share', 0));
    }

    #[Test]
    public function summary_requires_permission(): void
    {
        $this->actingAs($this->manager)->get('/crm/reserves')->assertForbidden();
    }

    #[Test]
    public function head_saves_override_and_reset_deletes_row(): void
    {
        $partner = User::factory()->create(['reserve_allowed' => true]);

        $this->actingAs($this->head)
            ->put("/crm/reserves/{$partner->id}", ['disabled' => true, 'hours' => 6])
            ->assertRedirect();

        $this->assertDatabaseHas('order_reserve_overrides', [
            'user_id' => $partner->id,
            'disabled' => true,
            'hours' => 6,
            'created_by' => $this->head->id,
        ]);

        // Выключенный тумблер + пустое окно = возврат к умолчаниям = строка удалена
        $this->actingAs($this->head)
            ->put("/crm/reserves/{$partner->id}", ['disabled' => false, 'hours' => null])
            ->assertRedirect();

        $this->assertDatabaseMissing('order_reserve_overrides', ['user_id' => $partner->id]);
    }

    #[Test]
    public function manager_cannot_save_override(): void
    {
        $partner = User::factory()->create(['reserve_allowed' => true]);

        $this->actingAs($this->manager)
            ->put("/crm/reserves/{$partner->id}", ['disabled' => true, 'hours' => null])
            ->assertForbidden();
    }

    #[Test]
    public function outcomes_are_recorded_by_actions_and_command(): void
    {
        $partner = User::factory()->create(['reserve_allowed' => true]);
        $publisher = app(\App\Services\Erp\OrderReservePublisher::class);
        $actions = app(\App\Services\Order\ClientOrderActions::class);

        $confirmed = Order::factory()->create([
            'user_id' => $partner->id, 'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true, 'reserved_until' => now()->addDay(),
        ]);
        $actions->confirmReserve($confirmed, $publisher);
        $this->assertSame('confirmed', $confirmed->refresh()->reserve_outcome);

        $cancelled = Order::factory()->create([
            'user_id' => $partner->id, 'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true, 'reserved_until' => now()->addDay(),
        ]);
        $actions->cancel($cancelled, $publisher);
        $this->assertSame('cancelled', Order::withTrashed()->find($cancelled->id)->reserve_outcome);

        $expired = Order::factory()->create([
            'user_id' => $partner->id, 'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true, 'reserved_until' => now()->subMinutes(5),
        ]);
        $this->artisan('reserve:release-expired')->assertSuccessful();
        $this->assertSame('expired', Order::withTrashed()->find($expired->id)->reserve_outcome);
    }
}
