<?php

namespace Tests\Feature\Wms;

use App\Models\DefectType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Справочник дефектов в кабинете склада: ведёт начальник склада,
 * кладовщик только пользуется чипами.
 */
class WmsDefectTypeControllerTest extends TestCase
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
    public function warehouse_head_sees_defect_types(): void
    {
        // Справочник наполнен миграцией — сверяемся по счётчику, а не по пустой таблице.
        DefectType::create(['name' => 'Оторван шильдик', 'is_active' => true, 'sort_order' => 100]);

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->get('/wms/defect-types')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/DefectTypes/Index')
                ->has('types', DefectType::count())
                ->where('types.'.(DefectType::count() - 1).'.name', 'Оторван шильдик')
            );
    }

    #[Test]
    public function warehouse_head_creates_defect_type(): void
    {
        $this->actingAs($this->userWithRole('warehouse-head'))
            ->post('/wms/defect-types', ['name' => 'Погнут кронштейн'])
            ->assertRedirect();

        $this->assertDatabaseHas('defect_types', ['name' => 'Погнут кронштейн', 'is_active' => true]);
    }

    #[Test]
    public function duplicate_defect_type_is_rejected(): void
    {
        $before = DefectType::count();

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->post('/wms/defect-types', ['name' => DefectType::query()->value('name')])
            ->assertSessionHasErrors('name');

        $this->assertSame($before, DefectType::count());
    }

    #[Test]
    public function warehouse_head_renames_and_hides_defect_type(): void
    {
        $type = DefectType::create(['name' => 'Скол ножки', 'is_active' => true, 'sort_order' => 100]);

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->put("/wms/defect-types/{$type->id}", ['name' => 'Скол на ножке', 'is_active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('defect_types', [
            'id' => $type->id,
            'name' => 'Скол на ножке',
            'is_active' => false,
        ]);
    }

    #[Test]
    public function warehouse_head_deletes_defect_type(): void
    {
        $type = DefectType::create(['name' => 'Разбито стекло', 'is_active' => true, 'sort_order' => 100]);

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->delete("/wms/defect-types/{$type->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('defect_types', ['id' => $type->id]);
    }

    #[Test]
    public function storekeeper_cannot_manage_defect_types(): void
    {
        $type = DefectType::create(['name' => 'Стёрта маркировка', 'is_active' => true, 'sort_order' => 100]);
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->get('/wms/defect-types')->assertForbidden();
        $this->actingAs($storekeeper)->post('/wms/defect-types', ['name' => 'Новая формулировка'])->assertForbidden();
        $this->actingAs($storekeeper)->delete("/wms/defect-types/{$type->id}")->assertForbidden();

        $this->assertDatabaseHas('defect_types', ['id' => $type->id]);
    }

    #[Test]
    public function client_without_wms_access_is_redirected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wms/defect-types')
            ->assertRedirect('/');
    }
}
