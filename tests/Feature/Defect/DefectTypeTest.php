<?php

namespace Tests\Feature\Defect;

use App\Models\DefectType;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Справочник типовых дефектов: управление в админке + чипы в WMS-форме.
 */
class DefectTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Миграция наполняет справочник дефолтами; чистим для предсказуемости.
        DefectType::query()->delete();
    }

    private function buyer(): User
    {
        $role = Role::firstOrCreate(['name' => 'buyer-manager', 'guard_name' => 'web']);
        foreach (['defect-types.view', 'defect-types.create', 'defect-types.edit', 'defect-types.delete'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create();
        $user->assignRole('storekeeper');

        return $user;
    }

    #[Test]
    public function buyer_manages_the_dictionary(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->post('/admin/defect-types', ['name' => 'Помята упаковка'])
            ->assertRedirect();

        $this->assertDatabaseHas('defect_types', ['name' => 'Помята упаковка', 'is_active' => true]);

        $type = DefectType::first();

        $this->actingAs($buyer)
            ->put("/admin/defect-types/{$type->id}", ['name' => 'Помята коробка', 'is_active' => false])
            ->assertRedirect();

        $type->refresh();
        $this->assertSame('Помята коробка', $type->name);
        $this->assertFalse($type->is_active);

        $this->actingAs($buyer)
            ->delete("/admin/defect-types/{$type->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('defect_types', ['id' => $type->id]);
    }

    #[Test]
    public function duplicate_name_is_rejected(): void
    {
        DefectType::create(['name' => 'Царапины', 'sort_order' => 1]);

        $this->actingAs($this->buyer())
            ->post('/admin/defect-types', ['name' => 'Царапины'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, DefectType::where('name', 'Царапины')->count());
    }

    #[Test]
    public function storekeeper_cannot_manage_dictionary(): void
    {
        // Кладовщик — WMS-only роль: admin-middleware не пускает его в /admin вовсе.
        $this->actingAs($this->storekeeper())
            ->post('/admin/defect-types', ['name' => 'Что-то'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('defect_types', ['name' => 'Что-то']);
    }

    #[Test]
    public function wms_create_form_exposes_active_types_only(): void
    {
        $active = DefectType::create(['name' => 'Активный', 'is_active' => true, 'sort_order' => 1]);
        DefectType::create(['name' => 'Скрытый', 'is_active' => false, 'sort_order' => 2]);
        Warehouse::factory()->defect()->create();

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Create')
                ->where('defectTypes', [['id' => $active->id, 'name' => 'Активный']])
            );
    }

    #[Test]
    public function types_are_ordered_by_sort_order(): void
    {
        $second = DefectType::create(['name' => 'Второй', 'is_active' => true, 'sort_order' => 2]);
        $first = DefectType::create(['name' => 'Первый', 'is_active' => true, 'sort_order' => 1]);
        Warehouse::factory()->defect()->create();

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('defectTypes', [
                ['id' => $first->id, 'name' => 'Первый'],
                ['id' => $second->id, 'name' => 'Второй'],
            ]));
    }
}
