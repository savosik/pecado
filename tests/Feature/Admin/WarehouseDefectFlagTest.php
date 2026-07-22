<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Флаг «склад некондиции» (is_defect) в CRUD складов админки.
 */
class WarehouseDefectFlagTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'wh-admin', 'guard_name' => 'web']);
        foreach (['warehouses.view', 'warehouses.create', 'warehouses.edit'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function store_persists_is_defect_flag(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.warehouses.store'), [
                'name' => 'Москва некондиция',
                'external_id' => 'wh-defect-ext',
                'is_defect' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Москва некондиция',
            'is_defect' => true,
        ]);
    }

    #[Test]
    public function store_defaults_is_defect_to_false(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.warehouses.store'), [
                'name' => 'Обычный склад',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Обычный склад',
            'is_defect' => false,
        ]);
    }

    #[Test]
    public function update_toggles_is_defect_flag(): void
    {
        $warehouse = Warehouse::factory()->create(['is_defect' => false]);

        $this->actingAs($this->admin())
            ->put(route('admin.warehouses.update', $warehouse->id), [
                'name' => $warehouse->name,
                'external_id' => $warehouse->external_id,
                'is_defect' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($warehouse->fresh()->is_defect);
    }

    #[Test]
    public function show_exposes_is_defect(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.warehouses.show', $warehouse->id))
            ->assertInertia(fn ($page) => $page->where('warehouse.is_defect', true));
    }
}
