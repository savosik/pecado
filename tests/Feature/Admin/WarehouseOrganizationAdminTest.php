<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Привязка организации к складу (warehouses.organization_id) в CRUD складов
 * админки — справочная, независимая от отправки в 1С.
 */
class WarehouseOrganizationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'wh-org-admin', 'guard_name' => 'web']);
        foreach (['warehouses.view', 'warehouses.create', 'warehouses.edit'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function store_persists_organization_id(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.warehouses.store'), [
                'name' => 'Москва персональный',
                'organization_id' => $organization->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Москва персональный',
            'organization_id' => $organization->id,
        ]);
    }

    #[Test]
    public function store_without_organization_leaves_it_null(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.warehouses.store'), ['name' => 'Без организации'])
            ->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Без организации',
            'organization_id' => null,
        ]);
    }

    #[Test]
    public function update_changes_organization(): void
    {
        $warehouse = Warehouse::factory()->create(['organization_id' => null]);
        $organization = Organization::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.warehouses.update', $warehouse), [
                'name' => $warehouse->name,
                'organization_id' => $organization->id,
            ])
            ->assertRedirect();

        $this->assertSame($organization->id, $warehouse->fresh()->organization_id);
    }

    #[Test]
    public function unknown_organization_id_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.warehouses.store'), [
                'name' => 'Неизвестная организация',
                'organization_id' => 999999,
            ])
            ->assertSessionHasErrors('organization_id');

        $this->assertDatabaseMissing('warehouses', ['name' => 'Неизвестная организация']);
    }

    #[Test]
    public function show_exposes_organization_name(): void
    {
        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.warehouses.show', $warehouse))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('warehouse.organization.name', 'ООО Пекадо'));
    }
}
