<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Product\ProductQueryService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Единая точка SQL остатков и мемоизация складов региона (карточка buf-03).
 *
 * Инварианты: StockService скоуплен на запрос (карточка + варианты + похожие
 * резолвят регион один раз), комбинированная карта — 2 запроса на холодную,
 * 1 на тёплую; адаптеры ProductQueryService дают те же числа, что и карты.
 */
class StockServiceMemoizationTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private Warehouse $primary;

    private Warehouse $preorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create();
        $this->primary = Warehouse::factory()->create();
        $this->preorder = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            [
                'region_id' => $this->region->id,
                'warehouse_id' => $this->primary->id,
                'type' => 'primary',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'region_id' => $this->region->id,
                'warehouse_id' => $this->preorder->id,
                'type' => 'preorder',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_container_scopes_single_instance_per_request(): void
    {
        $this->assertSame(
            app(StockService::class),
            app(StockService::class),
            'StockService должен быть скоуплен: один экземпляр на запрос',
        );
    }

    public function test_region_warehouses_are_resolved_once(): void
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);
        $service = app(StockService::class);

        $first = $service->regionWarehouseIds($user);

        $count = $this->countQueries(function () use ($service, $user) {
            $service->regionWarehouseIds($user);
            $service->regionWarehouseIds($user);
        });

        $this->assertSame(0, $count, 'Повторные резолвы складов региона не должны ходить в БД');
        $this->assertSame([$this->primary->id], $first['primary']);
        $this->assertSame([$this->preorder->id], $first['preorder']);
    }

    public function test_guest_default_region_is_memoized(): void
    {
        $service = app(StockService::class);
        $service->regionWarehouseIds(null);

        $count = $this->countQueries(fn () => $service->regionWarehouseIds(null));

        $this->assertSame(0, $count, 'Region::defaultId() для гостя резолвится один раз на запрос');
    }

    public function test_combined_maps_cost_one_query_when_warm(): void
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);
        $product = Product::factory()->create();
        $product->warehouses()->attach($this->primary->id, ['quantity' => 7]);
        $product->warehouses()->attach($this->preorder->id, ['quantity' => 3]);

        $service = app(StockService::class);
        $service->regionWarehouseIds($user); // прогрев

        $maps = null;
        $count = $this->countQueries(function () use ($service, $product, $user, &$maps) {
            $maps = $service->getStockMaps([$product], $user);
        });

        $this->assertSame(1, $count, 'Тёплая комбинированная карта — один запрос к product_warehouse');
        $this->assertSame(7, $maps['available'][$product->id]);
        $this->assertSame(3, $maps['preorder'][$product->id]);
    }

    public function test_enrich_adapter_matches_stock_maps(): void
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);
        $this->actingAs($user);

        $product = Product::factory()->create();
        $product->warehouses()->attach($this->primary->id, ['quantity' => 5]);
        $product->warehouses()->attach($this->preorder->id, ['quantity' => 2]);

        $enriched = ProductQueryService::enrichProductsWithStock([
            ['id' => $product->id, 'name' => $product->name],
        ]);

        $this->assertSame(5, $enriched[0]['stock_quantity']);
        $this->assertSame(2, $enriched[0]['preorder_quantity']);
    }

    public function test_stock_subselect_aliases_match_maps(): void
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);
        $this->actingAs($user);

        $product = Product::factory()->create();
        $product->warehouses()->attach($this->primary->id, ['quantity' => 9]);

        $query = Product::query()->select('products.*');
        ProductQueryService::withRegionStockSums($query);
        $row = $query->firstOrFail();

        $this->assertSame(9, (int) $row->primary_stock);
        $this->assertSame(0, (int) $row->preorder_stock);
    }
}
