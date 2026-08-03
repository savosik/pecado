<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Метрики продаж на дашборде считаются по заказам клиентов.
 *
 * Сотрудники тоже оформляют заказы, и часть из них тестовые — в выручке
 * и среднем чеке им не место. Заказы без user_id (партнёрские из 1С)
 * при этом остаются: покупатель у них настоящий.
 */
class AdminDashboardClientOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    private function closedOrder(?User $user, float $amount): Order
    {
        return Order::factory()->create([
            'user_id' => $user?->id,
            'status' => OrderStatus::CLOSED,
            'total_amount' => $amount,
        ]);
    }

    #[Test]
    public function revenue_ignores_staff_orders(): void
    {
        $client = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->closedOrder($client, 1000);
        $this->closedOrder($staff, 5000);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totalRevenue', fn ($value) => (float) $value === 1000.0)
                ->where('stats.totalOrders', 1)
                ->where('stats.completedOrders', 1)
                ->where('stats.avgOrderValue', fn ($value) => (float) $value === 1000.0)
            );
    }

    #[Test]
    public function partner_orders_without_user_are_still_counted(): void
    {
        // Партнёрский заказ из 1С: пользователя сайта нет, деньги есть.
        $this->closedOrder(null, 2000);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totalRevenue', fn ($value) => (float) $value === 2000.0)
                ->where('stats.totalOrders', 1)
            );
    }

    #[Test]
    public function recent_orders_exclude_staff(): void
    {
        $client = User::factory()->create(['name' => 'Клиент Пекадо']);
        $staff = User::factory()->staff()->create(['name' => 'Закупщик Пекадо']);

        $this->closedOrder($client, 100);
        $this->closedOrder($staff, 100);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $response->assertInertia(function (AssertableInertia $page) {
            $names = array_column($page->toArray()['props']['recentOrders'], 'user_name');

            $this->assertSame(['Клиент Пекадо'], $names);
        });
    }

    #[Test]
    public function service_account_orders_are_excluded_too(): void
    {
        $service = User::factory()->service()->create();
        $this->closedOrder($service, 777);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totalOrders', 0)
                ->where('stats.totalRevenue', fn ($value) => (float) $value === 0.0)
            );
    }
}
