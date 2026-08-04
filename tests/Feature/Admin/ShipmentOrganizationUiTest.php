<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Организация и склад реализации в витрине админки (v15.8.0, карточка org-04).
 *
 * Реализация — документ, по которому клиент сверяет накладную, поэтому «чьё юрлицо
 * и с какого склада» должно читаться и в списке, и в карточке, и в фильтрах.
 */
class ShipmentOrganizationUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'shipment-admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'shipments.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makeShipment(array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'date' => now()->toDateString(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
        ], $attributes));
    }

    #[Test]
    public function admin_shipment_card_shows_organization_and_warehouse(): void
    {
        config(['erp.organizations.enabled' => true]);

        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $warehouse = Warehouse::factory()->create(['name' => 'Тюмень Основной']);
        $shipment = $this->makeShipment([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.shipments.show', $shipment->id))
            ->assertInertia(fn ($page) => $page
                ->where('shipment.organization.name', 'ООО Пекадо')
                ->where('shipment.organization.is_stub', false)
                ->where('shipment.warehouse.name', 'Тюмень Основной')
                ->where('organizationsEnabled', true)
            );
    }

    #[Test]
    public function admin_shipment_list_carries_organization_and_warehouse(): void
    {
        config(['erp.organizations.enabled' => true]);

        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $warehouse = Warehouse::factory()->create(['name' => 'Тюмень Основной']);
        $this->makeShipment([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.shipments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('shipments.data.0.organization.name', 'ООО Пекадо')
                ->where('shipments.data.0.warehouse.name', 'Тюмень Основной')
                ->has('warehouses')
            );
    }

    #[Test]
    public function admin_shipment_list_filters_by_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create();
        $matching = $this->makeShipment(['warehouse_id' => $warehouse->id]);
        $this->makeShipment();

        $this->actingAs($this->admin())
            ->get(route('admin.shipments.index', ['warehouse_id' => $warehouse->id]))
            ->assertInertia(fn ($page) => $page
                ->has('shipments.data', 1)
                ->where('shipments.data.0.id', $matching->id)
            );
    }

    /**
     * «Не указан» — переходный период: 1С прислала реализацию до того, как научилась
     * указывать склад. Отобрать нужно именно такие.
     */
    #[Test]
    public function admin_shipment_list_filters_shipments_without_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create();
        $this->makeShipment(['warehouse_id' => $warehouse->id]);
        $without = $this->makeShipment();

        $this->actingAs($this->admin())
            ->get(route('admin.shipments.index', ['warehouse_id' => 'none']))
            ->assertInertia(fn ($page) => $page
                ->has('shipments.data', 1)
                ->where('shipments.data.0.id', $without->id)
            );
    }

    /**
     * Заглушка обязана быть заметна: у неё вместо названия UUID из 1С, и без бейджа
     * админ не поймёт, что юрлицо ещё не заведено.
     */
    #[Test]
    public function stub_organization_is_marked_in_admin(): void
    {
        config(['erp.organizations.enabled' => true]);

        $organization = Organization::factory()->stub()->create();
        $shipment = $this->makeShipment(['organization_id' => $organization->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.shipments.show', $shipment->id))
            ->assertInertia(fn ($page) => $page->where('shipment.organization.is_stub', true));
    }
}
