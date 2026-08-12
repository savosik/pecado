<?php

namespace Tests\Feature\Crm;

use App\Models\Currency;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\ClientLastOrderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Колонка последнего заказа в списке партнёров (crm-22).
 */
class ClientLastOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            ...$attributes,
        ]);
    }

    /**
     * Главная ловушка раздела: историю заказов импортировали из 1С в мае 2026,
     * поэтому `created_at` у старых документов — дата импорта, а не сделки.
     * Последним обязан считаться заказ с большей бизнес-датой, даже если
     * записан в базу раньше.
     */
    #[Test]
    public function last_order_is_picked_by_business_date_not_by_row_creation(): void
    {
        // Записан позже, но документ старый.
        $this->order([
            'created_at' => now(),
            'erp_created_at' => now()->subYear(),
            'total_amount' => 111,
        ]);

        // Записан раньше, но документ свежий.
        $fresh = $this->order([
            'created_at' => now()->subMonth(),
            'erp_created_at' => now()->subDay(),
            'total_amount' => 222,
        ]);

        $last = app(ClientLastOrderService::class)->forClients([$this->client->id]);

        $this->assertSame(222.0, $last[$this->client->id]['amount_rub']);
        $this->assertSame(
            $fresh->erp_created_at->toDateString(),
            $last[$this->client->id]['at'],
        );
    }

    #[Test]
    public function amount_is_converted_to_rubles_like_analytics_does(): void
    {
        Currency::factory()->create(['code' => 'BYN', 'is_base' => false, 'exchange_rate' => 30]);

        $this->order(['currency_code' => 'BYN', 'total_amount' => 100, 'erp_created_at' => now()]);

        $last = app(ClientLastOrderService::class)->forClients([$this->client->id]);

        $this->assertSame(3000.0, $last[$this->client->id]['amount_rub']);
    }

    #[Test]
    public function client_without_orders_has_no_row_instead_of_zero(): void
    {
        $last = app(ClientLastOrderService::class)->forClients([$this->client->id]);

        // Отсутствие ключа, а не сумма 0: «не заказывал ни разу» и «заказал на ноль» —
        // разные вещи, и в колонке они выглядят по-разному.
        $this->assertArrayNotHasKey($this->client->id, $last);
    }

    #[Test]
    public function deleted_orders_do_not_become_the_last_one(): void
    {
        $kept = $this->order(['erp_created_at' => now()->subDays(3), 'total_amount' => 500]);
        $this->order(['erp_created_at' => now(), 'total_amount' => 900])->delete();

        $last = app(ClientLastOrderService::class)->forClients([$this->client->id]);

        $this->assertSame(500.0, $last[$this->client->id]['amount_rub']);
        $this->assertSame($kept->erp_created_at->toDateString(), $last[$this->client->id]['at']);
    }

    #[Test]
    public function the_column_reaches_the_list(): void
    {
        $this->order(['erp_created_at' => now()->subDays(2), 'total_amount' => 777]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.data.0.last_order.amount_rub', 777));
    }

    #[Test]
    public function no_order_filter_hides_recent_buyers(): void
    {
        $this->order(['erp_created_at' => now()->subDays(5)]);

        $silent = User::factory()->create([
            'personal_manager_id' => $this->client->personal_manager_id,
        ]);
        Order::factory()->create([
            'user_id' => $silent->id,
            'currency_code' => 'RUB',
            'erp_created_at' => now()->subDays(120),
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['no_order_days' => 90]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 1)
                ->where('clients.data.0.id', $silent->id));
    }

    #[Test]
    public function amount_filter_matches_the_column_value(): void
    {
        $this->order(['erp_created_at' => now(), 'total_amount' => 50_000]);

        $big = User::factory()->create(['personal_manager_id' => $this->client->personal_manager_id]);
        Order::factory()->create([
            'user_id' => $big->id,
            'currency_code' => 'RUB',
            'erp_created_at' => now(),
            'total_amount' => 200_000,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['order_amount_from' => 100_000]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 1)
                ->where('clients.data.0.id', $big->id));
    }

    #[Test]
    public function sorting_puts_clients_without_orders_last(): void
    {
        $this->order(['erp_created_at' => now()->subDays(10)]);
        $never = User::factory()->create(['personal_manager_id' => $this->client->personal_manager_id]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['sort_by' => 'last_order_at', 'sort_order' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.data.0.id', $this->client->id)
                ->where('clients.data.1.id', $never->id));
    }
}
