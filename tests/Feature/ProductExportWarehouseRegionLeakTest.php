<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Лазейка: складские поля выгрузки читают $product->warehouses напрямую и
 * исторически отдавали клиенту остатки складов вне его региона. Партнёр,
 * настроивший выгрузку с колонкой «Москва персональный» до введения
 * региональных ограничений, продолжал получать по ней остатки.
 *
 * Тесты фиксируют, что после фикса (RestrictsWarehousesByRegion) такие
 * колонки видят только склады, доступные региону клиента.
 */
class ProductExportWarehouseRegionLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{product: Product, regionWithAccess: Region, regionNoAccess: Region, personalWarehouse: Warehouse, regionalWarehouse: Warehouse}
     */
    private function seedWarehouses(): array
    {
        $personalWarehouse = Warehouse::factory()->create(['name' => 'Москва персональный']);
        $regionalWarehouse = Warehouse::factory()->create(['name' => 'Тюмень']);

        // Регион, которому персональный склад доступен.
        $regionWithAccess = Region::factory()->create(['name' => 'С доступом']);
        DB::table('region_warehouse')->insert([
            ['region_id' => $regionWithAccess->id, 'warehouse_id' => $personalWarehouse->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $regionWithAccess->id, 'warehouse_id' => $regionalWarehouse->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Регион без доступа к персональному складу — только региональный.
        $regionNoAccess = Region::factory()->create(['name' => 'Без доступа']);
        DB::table('region_warehouse')->insert([
            ['region_id' => $regionNoAccess->id, 'warehouse_id' => $regionalWarehouse->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $product = Product::factory()->create();
        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $personalWarehouse->id, 'quantity' => 42],
            ['product_id' => $product->id, 'warehouse_id' => $regionalWarehouse->id, 'quantity' => 7],
        ]);

        return compact('product', 'regionWithAccess', 'regionNoAccess', 'personalWarehouse', 'regionalWarehouse');
    }

    private function makeExport(array $fields, Product $product, ?User $client): ProductExport
    {
        return (new ProductExport)->forceFill([
            'client_user_id' => $client?->id,
            'fields' => $fields,
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);
    }

    public function test_personal_warehouse_column_hidden_for_client_without_region_access(): void
    {
        $data = $this->seedWarehouses();
        $client = User::factory()->create(['region_id' => $data['regionNoAccess']->id]);
        $service = app(ProductExportService::class);

        $export = $this->makeExport([
            ['key' => "warehouse.{$data['personalWarehouse']->id}.quantity"],
            ['key' => "warehouse.{$data['regionalWarehouse']->id}.quantity"],
        ], $data['product'], $client);

        $row = $service->fetchData($export, 1)->first();

        // Персональный склад вне региона => 0, региональный => фактический остаток.
        $this->assertSame(0, $row["warehouse.{$data['personalWarehouse']->id}.quantity"]);
        $this->assertSame(7, $row["warehouse.{$data['regionalWarehouse']->id}.quantity"]);
    }

    public function test_personal_warehouse_column_visible_for_client_with_region_access(): void
    {
        $data = $this->seedWarehouses();
        $client = User::factory()->create(['region_id' => $data['regionWithAccess']->id]);
        $service = app(ProductExportService::class);

        $export = $this->makeExport([
            ['key' => "warehouse.{$data['personalWarehouse']->id}.quantity"],
        ], $data['product'], $client);

        $row = $service->fetchData($export, 1)->first();

        $this->assertSame(42, $row["warehouse.{$data['personalWarehouse']->id}.quantity"]);
    }

    public function test_total_stock_excludes_out_of_region_warehouse(): void
    {
        $data = $this->seedWarehouses();
        $client = User::factory()->create(['region_id' => $data['regionNoAccess']->id]);
        $service = app(ProductExportService::class);

        $export = $this->makeExport([['key' => 'total_stock']], $data['product'], $client);

        $row = $service->fetchData($export, 1)->first();

        // Только региональный склад (7), персональный (42) не учитывается.
        $this->assertSame(7, $row['total_stock']);
    }

    public function test_warehouses_name_list_excludes_out_of_region_warehouse(): void
    {
        $data = $this->seedWarehouses();
        $client = User::factory()->create(['region_id' => $data['regionNoAccess']->id]);
        $service = app(ProductExportService::class);

        $export = $this->makeExport([['key' => 'warehouses.name']], $data['product'], $client);

        $row = $service->fetchData($export, 1)->first();

        $this->assertStringContainsString('Тюмень', (string) $row['warehouses.name']);
        $this->assertStringNotContainsString('Москва персональный', (string) $row['warehouses.name']);
    }

    public function test_admin_export_without_client_sees_all_warehouses(): void
    {
        $data = $this->seedWarehouses();
        $service = app(ProductExportService::class);

        // client_user_id = null — внутренняя админская выгрузка, без ограничений.
        $export = $this->makeExport([['key' => 'total_stock']], $data['product'], null);

        $row = $service->fetchData($export, 1)->first();

        $this->assertSame(49, $row['total_stock']);
    }
}
