<?php

namespace Tests\Unit\Services\Stock;

use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockServiceBatchMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_array_for_empty_collection(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], (new StockService)->getAvailableStockMap(collect(), $user));
    }

    public function test_returns_zero_for_each_product_when_region_unknown(): void
    {
        // Без регионов в БД — defaultId() == null, fallback в нули.
        $user = User::factory()->create(['region_id' => null]);

        $p1 = Product::create(['name' => 'p1', 'base_price' => 10, 'external_id' => 'sb-no-region-1']);
        $p2 = Product::create(['name' => 'p2', 'base_price' => 10, 'external_id' => 'sb-no-region-2']);

        $map = (new StockService)->getAvailableStockMap(collect([$p1, $p2]), $user);

        $this->assertSame([$p1->id => 0, $p2->id => 0], $map);
    }

    public function test_sums_only_primary_warehouses_of_user_region(): void
    {
        $region = Region::factory()->create();
        $otherRegion = Region::factory()->create();
        $user = User::factory()->create(['region_id' => $region->id]);

        $primary = Warehouse::factory()->create();
        $primaryExtra = Warehouse::factory()->create();
        $preorder = Warehouse::factory()->create();
        $foreignRegionWarehouse = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $primary->id, 'type' => 'primary'],
            ['region_id' => $region->id, 'warehouse_id' => $primaryExtra->id, 'type' => 'primary'],
            ['region_id' => $region->id, 'warehouse_id' => $preorder->id, 'type' => 'preorder'],
            ['region_id' => $otherRegion->id, 'warehouse_id' => $foreignRegionWarehouse->id, 'type' => 'primary'],
        ]);

        $p1 = Product::create(['name' => 'p1', 'base_price' => 10, 'external_id' => 'sb-prim-1']);
        $p2 = Product::create(['name' => 'p2', 'base_price' => 10, 'external_id' => 'sb-prim-2']);
        $p3 = Product::create(['name' => 'p3', 'base_price' => 10, 'external_id' => 'sb-prim-3']);

        DB::table('product_warehouse')->insert([
            ['product_id' => $p1->id, 'warehouse_id' => $primary->id, 'quantity' => 5],
            ['product_id' => $p1->id, 'warehouse_id' => $primaryExtra->id, 'quantity' => 7],
            ['product_id' => $p1->id, 'warehouse_id' => $preorder->id, 'quantity' => 100],
            ['product_id' => $p1->id, 'warehouse_id' => $foreignRegionWarehouse->id, 'quantity' => 999],
            ['product_id' => $p2->id, 'warehouse_id' => $primary->id, 'quantity' => 3],
            // p3 без остатков
        ]);

        $map = (new StockService)->getAvailableStockMap(collect([$p1, $p2, $p3]), $user);

        $this->assertSame(12, $map[$p1->id]);
        $this->assertSame(3, $map[$p2->id]);
        $this->assertSame(0, $map[$p3->id]);
    }

    public function test_uses_constant_number_of_queries_regardless_of_collection_size(): void
    {
        $region = Region::factory()->create();
        $user = User::factory()->create(['region_id' => $region->id]);
        $warehouse = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $warehouse->id, 'type' => 'primary'],
        ]);

        $products = collect();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $product = Product::create([
                'name' => "p{$i}",
                'base_price' => 10,
                'external_id' => "sb-q-{$i}",
            ]);
            $products->push($product);
            $rows[] = ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => $i];
        }
        DB::table('product_warehouse')->insert($rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            (new StockService)->getAvailableStockMap($products, $user);
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        // 1) SELECT warehouse_id FROM region_warehouse, 2) SELECT product_id, SUM(quantity) FROM product_warehouse
        $this->assertSame(2, $count, "Должно быть ровно 2 SQL-запроса, фактически {$count}");
    }
}
