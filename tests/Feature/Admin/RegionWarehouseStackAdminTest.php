<?php

namespace Tests\Feature\Admin;

use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Админка регионов: настройка стопки складов через RegionController.
 * Порядок primary_warehouse_ids[] должен сохраняться как priority (1 — верхний);
 * флаг stock_stack_enabled требует хотя бы один склад наличия.
 */
class RegionWarehouseStackAdminTest extends TestCase
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

    #[Test]
    public function создание_региона_сохраняет_порядок_складов_как_приоритет(): void
    {
        $top = Warehouse::factory()->create();
        $middle = Warehouse::factory()->create();
        $bottom = Warehouse::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('admin.regions.store'), [
            'name' => 'Стопочный регион',
            'stock_stack_enabled' => true,
            'primary_warehouse_ids' => [$top->id, $middle->id, $bottom->id],
        ]);

        $response->assertRedirect();

        $region = Region::where('name', 'Стопочный регион')->firstOrFail();
        $this->assertTrue((bool) $region->stock_stack_enabled);

        $priorities = DB::table('region_warehouse')
            ->where('region_id', $region->id)
            ->where('type', 'primary')
            ->pluck('priority', 'warehouse_id');

        $this->assertSame(1, $priorities[$top->id]);
        $this->assertSame(2, $priorities[$middle->id]);
        $this->assertSame(3, $priorities[$bottom->id]);
    }

    #[Test]
    public function обновление_меняет_порядок_складов(): void
    {
        $w1 = Warehouse::factory()->create();
        $w2 = Warehouse::factory()->create();

        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $w1->id, 'type' => 'primary', 'priority' => 1],
            ['region_id' => $region->id, 'warehouse_id' => $w2->id, 'type' => 'primary', 'priority' => 2],
        ]);

        // Меняем местами: w2 теперь верхний
        $response = $this->actingAs($this->admin)->put(route('admin.regions.update', $region), [
            'name' => $region->name,
            'stock_stack_enabled' => true,
            'primary_warehouse_ids' => [$w2->id, $w1->id],
        ]);

        $response->assertRedirect();

        $priorities = DB::table('region_warehouse')
            ->where('region_id', $region->id)
            ->where('type', 'primary')
            ->pluck('priority', 'warehouse_id');

        $this->assertSame(1, $priorities[$w2->id]);
        $this->assertSame(2, $priorities[$w1->id]);
    }

    #[Test]
    public function выключенный_режим_стопки_не_требует_складов_и_остатки_суммируются(): void
    {
        // Приоритет сохраняется всегда (порядок формы) — админ может
        // расставить стопку заранее, до включения режима. Но пока флаг
        // выключен, StockService игнорирует priority и суммирует остатки —
        // это и есть наблюдаемое поведение «выключено», а не сам факт
        // отсутствия колонки в БД.
        $warehouse = Warehouse::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('admin.regions.store'), [
            'name' => 'Обычный регион',
            'stock_stack_enabled' => false,
            'primary_warehouse_ids' => [$warehouse->id],
        ]);

        $response->assertRedirect();

        $region = Region::where('name', 'Обычный регион')->firstOrFail();
        $this->assertFalse((bool) $region->stock_stack_enabled);

        $this->assertFalse(
            app(\App\Contracts\Stock\StockServiceInterface::class)->regionWarehouseIds(
                User::factory()->create(['region_id' => $region->id]),
            )['stack'],
        );
    }

    #[Test]
    public function приоритет_сохраняется_даже_при_выключенном_флаге(): void
    {
        // Порядок из формы фиксируется всегда — можно расставить стопку
        // заранее и включить режим позже без повторной сортировки складов.
        $top = Warehouse::factory()->create();
        $bottom = Warehouse::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('admin.regions.store'), [
            'name' => 'Регион с заготовленным порядком',
            'stock_stack_enabled' => false,
            'primary_warehouse_ids' => [$top->id, $bottom->id],
        ]);

        $response->assertRedirect();

        $region = Region::where('name', 'Регион с заготовленным порядком')->firstOrFail();

        $priorities = DB::table('region_warehouse')
            ->where('region_id', $region->id)
            ->where('type', 'primary')
            ->pluck('priority', 'warehouse_id');

        $this->assertSame(1, $priorities[$top->id]);
        $this->assertSame(2, $priorities[$bottom->id]);
    }

    #[Test]
    public function включение_стопки_без_складов_отклоняется_валидацией(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.regions.store'), [
            'name' => 'Пустая стопка',
            'stock_stack_enabled' => true,
            'primary_warehouse_ids' => [],
        ]);

        $response->assertSessionHasErrors('primary_warehouse_ids');
        $this->assertDatabaseMissing('regions', ['name' => 'Пустая стопка']);
    }

    #[Test]
    public function show_отдаёт_склады_в_порядке_приоритета(): void
    {
        $bottom = Warehouse::factory()->create(['name' => 'Нижний']);
        $top = Warehouse::factory()->create(['name' => 'Верхний']);

        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $bottom->id, 'type' => 'primary', 'priority' => 2],
            ['region_id' => $region->id, 'warehouse_id' => $top->id, 'type' => 'primary', 'priority' => 1],
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.regions.show', $region));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('region.stock_stack_enabled', true)
            ->where('region.primary_warehouses.0.name', 'Верхний')
            ->where('region.primary_warehouses.1.name', 'Нижний')
        );
    }
}
