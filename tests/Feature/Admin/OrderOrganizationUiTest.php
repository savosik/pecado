<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Организация заказа в витрине: админка и личный кабинет (v15.8.0, карточка org-03).
 */
class OrderOrganizationUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'order-admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'orders.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        return Order::withoutEvents(fn () => Order::create(array_merge([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending_approval',
            'total_amount' => 100,
        ], $attributes)));
    }

    // ──────────────────────────────────────────────
    // Админка
    // ──────────────────────────────────────────────

    #[Test]
    public function admin_order_card_shows_organization_and_warehouse(): void
    {
        config(['erp.organizations.enabled' => true]);

        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $warehouse = Warehouse::factory()->create(['name' => 'Москва основной']);
        $order = $this->makeOrder([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page
                ->where('order.organization.name', 'ООО Пекадо')
                ->where('order.warehouse.name', 'Москва основной')
                ->where('organizationsEnabled', true)
            );
    }

    #[Test]
    public function admin_order_list_filters_by_organization(): void
    {
        $organization = Organization::factory()->create();
        $matching = $this->makeOrder(['organization_id' => $organization->id]);
        $this->makeOrder();

        $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['organization_id' => $organization->id]))
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $matching->id)
            );
    }

    /**
     * «Не указана» — рабочий фильтр переходного периода, а не заглушка.
     */
    #[Test]
    public function admin_order_list_filters_orders_without_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->makeOrder(['organization_id' => $organization->id]);
        $without = $this->makeOrder();

        $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['organization_id' => 'none']))
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $without->id)
            );
    }

    // ──────────────────────────────────────────────
    // Личный кабинет
    // ──────────────────────────────────────────────

    #[Test]
    public function cabinet_shows_seller_when_flag_enabled(): void
    {
        config(['erp.organizations.enabled' => true]);

        $organization = Organization::factory()->create(['name' => 'ООО Пекадо', 'tax_id' => '7710140679']);
        $order = $this->makeOrder(['organization_id' => $organization->id]);

        $this->actingAs($order->user)
            ->get(route('cabinet.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page
                ->where('order.seller.name', 'ООО Пекадо')
                ->where('order.seller.tax_id', '7710140679')
            );
    }

    #[Test]
    public function cabinet_hides_seller_when_flag_disabled(): void
    {
        config(['erp.organizations.enabled' => false]);

        $organization = Organization::factory()->create();
        $order = $this->makeOrder(['organization_id' => $organization->id]);

        $this->actingAs($order->user)
            ->get(route('cabinet.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page->where('order.seller', null));
    }

    /**
     * У заглушки вместо названия лежит UUID — клиенту такое показывать нельзя.
     */
    #[Test]
    public function cabinet_hides_stub_organization_from_client(): void
    {
        config(['erp.organizations.enabled' => true]);

        $organization = Organization::factory()->stub()->create();
        $order = $this->makeOrder(['organization_id' => $organization->id]);

        $this->actingAs($order->user)
            ->get(route('cabinet.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page->where('order.seller', null));
    }
}
