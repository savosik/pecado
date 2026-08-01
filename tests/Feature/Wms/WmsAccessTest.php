<?php

namespace Tests\Feature\Wms;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/wms')->assertRedirect('/login');
    }

    #[Test]
    public function client_without_roles_cannot_enter_wms(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wms')
            ->assertRedirect('/');
    }

    #[Test]
    public function warehouse_head_can_enter_wms(): void
    {
        $this->actingAs($this->userWithRole('warehouse-head'))
            ->get('/wms')
            ->assertOk();
    }

    #[Test]
    public function storekeeper_can_enter_wms(): void
    {
        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms')
            ->assertOk();
    }

    #[Test]
    public function super_admin_can_enter_wms(): void
    {
        $this->actingAs($this->userWithRole('super-admin'))
            ->get('/wms')
            ->assertOk();
    }

    #[Test]
    public function content_manager_without_wms_permissions_cannot_enter_wms(): void
    {
        $this->actingAs($this->userWithRole('content-manager'))
            ->get('/wms')
            ->assertRedirect('/');
    }

    #[Test]
    public function sales_head_cannot_enter_wms(): void
    {
        // Права CRM не должны открывать склад — домены независимы.
        $this->actingAs($this->userWithRole('sales-head'))
            ->get('/wms')
            ->assertRedirect('/');
    }

    #[Test]
    public function wms_pages_render_through_the_wms_root_view(): void
    {
        // Ловит опечатку в HandleWmsInertiaRequests::$rootView: иначе склад
        // отрендерился бы в чужом blade, и это осталось бы незамеченным.
        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms')
            ->assertOk()
            ->assertSee('- Склад', escape: false)
            ->assertDontSee('Админ-панель');
    }

    #[Test]
    public function dashboard_returns_stock_figures_per_warehouse(): void
    {
        // Сводка считает все склады БД, включая справочные из миграций
        // («Москва подарки»), — изолируем тест от них
        Warehouse::query()->delete();

        $moscow = Warehouse::create(['name' => 'Москва Основной']);
        $tyumen = Warehouse::create(['name' => 'Тюмень Основной']);

        $inStock = Product::factory()->create();
        $zero = Product::factory()->create();

        $moscow->products()->attach([
            $inStock->id => ['quantity' => 7],
            $zero->id => ['quantity' => 0],
        ]);
        $tyumen->products()->attach([$inStock->id => ['quantity' => 3]]);

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->get('/wms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Dashboard')
                ->where('totals.warehouses', 2)
                ->where('totals.positions_in_stock', 2)   // 1 в Москве + 1 в Тюмени
                ->where('totals.units_total', 10)          // 7 + 3
                ->where('warehouses.0.name', 'Москва Основной')
                ->where('warehouses.0.positions_in_stock', 1)
                ->where('warehouses.0.positions_zero', 1)
                ->where('warehouses.0.units_total', 7)
            );
    }

    #[Test]
    public function warehouse_without_stock_is_still_listed(): void
    {
        Warehouse::query()->delete();

        // LEFT JOIN: пустой склад обязан попасть в сводку с нулями,
        // иначе кладовщик решит, что склада не существует.
        Warehouse::create(['name' => 'Пустой склад']);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totals.warehouses', 1)
                ->where('warehouses.0.name', 'Пустой склад')
                ->where('warehouses.0.positions_total', 0)
                ->where('warehouses.0.units_total', 0)
            );
    }
}
