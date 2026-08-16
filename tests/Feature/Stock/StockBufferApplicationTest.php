<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\ProductStockBuffer;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Product\ProductQueryService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Применение страхового буфера на витрине (карточка buf-04).
 *
 * Инварианты: флаг выключен или галочки нет → ни одного лишнего запроса и
 * ни байта различий; клиент сегмента видит max(0, sum − buffer) одинаково
 * в картах и подзапросах; preorder не занижается; таблица буферов читается
 * один раз на запрос.
 */
class StockBufferApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private Warehouse $primary;

    private Warehouse $preorder;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create();
        $this->primary = Warehouse::factory()->create();
        $this->preorder = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $this->primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $this->region->id, 'warehouse_id' => $this->preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->product = Product::factory()->create();
        $this->product->warehouses()->attach($this->primary->id, ['quantity' => 5]);
        $this->product->warehouses()->attach($this->preorder->id, ['quantity' => 4]);

        ProductStockBuffer::create(['product_id' => $this->product->id, 'buffer_qty' => 2]);
    }

    private function client(bool $flagged): User
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);

        if ($flagged) {
            $user->forceFill(['stock_buffer_enabled' => true])->save();
        }

        return $user;
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

    public function test_disabled_flag_changes_nothing_for_flagged_client(): void
    {
        config(['stock_buffer.enabled' => false]);
        $user = $this->client(true);

        $stock = app(StockService::class)->getStock($this->product, $user);

        $this->assertSame(['available' => 5, 'preorder' => 4], $stock);
    }

    public function test_client_without_flag_pays_zero_queries_for_buffers(): void
    {
        config(['stock_buffer.enabled' => true]);
        $user = $this->client(false);

        $service = app(StockService::class);
        $service->regionWarehouseIds($user); // прогрев мемо складов

        $queries = $this->countQueries(
            fn () => $service->getStock($this->product, $user),
        );

        $this->assertSame(['available' => 5, 'preorder' => 4], $service->getStock($this->product, $user));
        $this->assertSame(1, $queries, 'Без галочки — только product_warehouse, ни одного запроса за буферами');
    }

    public function test_flagged_client_sees_reduced_available_and_full_preorder(): void
    {
        config(['stock_buffer.enabled' => true]);
        $user = $this->client(true);

        $stock = app(StockService::class)->getStock($this->product, $user);

        $this->assertSame(['available' => 3, 'preorder' => 4], $stock, 'available = max(0, 5 − 2), preorder не занижается');
    }

    public function test_buffer_never_goes_below_zero(): void
    {
        config(['stock_buffer.enabled' => true]);
        ProductStockBuffer::query()->update(['manual_qty' => 9]);
        $user = $this->client(true);

        $this->assertSame(0, app(StockService::class)->getStock($this->product, $user)['available']);
    }

    public function test_buffer_table_is_read_once_per_request(): void
    {
        config(['stock_buffer.enabled' => true]);
        $user = $this->client(true);

        $service = app(StockService::class);
        $service->getStock($this->product, $user); // прогрев: склады + буферы

        $queries = $this->countQueries(function () use ($service, $user) {
            $service->getStock($this->product, $user);
            $service->getStockMapsByIds([$this->product->id], $user);
        });

        $this->assertSame(2, $queries, 'Только product_warehouse на вызов; таблица буферов — из мемо');
    }

    public function test_subselect_orders_by_reduced_stock_for_flagged_client(): void
    {
        config(['stock_buffer.enabled' => true]);
        $user = $this->client(true);
        $this->actingAs($user);

        $query = Product::query()->select('products.*');
        ProductQueryService::withRegionStockSums($query);
        $row = $query->firstOrFail();

        $this->assertSame(3, (int) $row->primary_stock, 'Подзапрос обязан считать так же, как карта: 5 − 2');
        $this->assertSame(4, (int) $row->preorder_stock);
    }

    public function test_subselect_unchanged_for_guest(): void
    {
        config(['stock_buffer.enabled' => true]);

        $query = Product::query()->select('products.*');
        ProductQueryService::withRegionStockSums($query);
        $row = $query->firstOrFail();

        $this->assertSame(5, (int) $row->primary_stock);
    }

    public function test_enrich_matches_subselect_for_flagged_client(): void
    {
        config(['stock_buffer.enabled' => true]);
        $user = $this->client(true);
        $this->actingAs($user);

        $enriched = ProductQueryService::enrichProductsWithStock([
            ['id' => $this->product->id],
        ]);

        $this->assertSame(3, $enriched[0]['stock_quantity'], 'Карточка и листинг показывают одно число');
        $this->assertSame(4, $enriched[0]['preorder_quantity']);
    }

    public function test_manual_zero_disables_reduction(): void
    {
        config(['stock_buffer.enabled' => true]);
        ProductStockBuffer::query()->update(['manual_qty' => 0]);
        $user = $this->client(true);

        $this->assertSame(5, app(StockService::class)->getStock($this->product, $user)['available']);
    }
}
