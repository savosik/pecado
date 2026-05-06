<?php

namespace Tests\Feature\Services\ProductExport\Presets;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductExport\Presets\AbstractPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class AbstractPresetBatchingTest extends TestCase
{
    use RefreshDatabase;

    private function makePreset(): AbstractPreset
    {
        return new class(app(PriceServiceInterface::class), app(StockServiceInterface::class)) extends AbstractPreset
        {
            public array $collected = [];

            public function key(): string
            {
                return 'test';
            }

            public function name(): string
            {
                return 'Test';
            }

            public function description(): string
            {
                return 'Test';
            }

            public function fileExtension(): string
            {
                return 'txt';
            }

            public function mimeType(): string
            {
                return 'text/plain';
            }

            public function color(): string
            {
                return 'gray';
            }

            public function icon(): string
            {
                return 'LuFile';
            }

            public function writeToStream($stream, ProductExport $export): void
            {
                $this->eachChunk($export, function ($items) {
                    foreach ($items as $item) {
                        $this->collected[] = $item;
                    }
                });
            }

            public function generate(ProductExport $export): StreamedResponse
            {
                return new StreamedResponse;
            }
        };
    }

    public function test_each_chunk_uses_batched_prices_and_stock_for_each_product(): void
    {
        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $warehouse->id, 'type' => 'primary'],
        ]);

        $client = User::factory()->create([
            'erp_id' => 'partner-batch-preset',
            'region_id' => $region->id,
        ]);

        $p1 = Product::create(['name' => 'p1', 'base_price' => 100.00, 'external_id' => 'pb-pre-1']);
        $p2 = Product::create(['name' => 'p2', 'base_price' => 200.00, 'external_id' => 'pb-pre-2']);
        $p3 = Product::create(['name' => 'p3', 'base_price' => 300.00, 'external_id' => 'pb-pre-3']);

        IndividualPrice::create([
            'partner_id' => $client->id,
            'product_id' => $p1->id,
            'warehouse_id' => $warehouse->id,
            'price' => 70.00,
        ]);

        DB::table('product_warehouse')->insert([
            ['product_id' => $p1->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5],
            ['product_id' => $p2->id, 'warehouse_id' => $warehouse->id, 'quantity' => 12],
            // p3 без остатков
        ]);

        $export = ProductExport::create([
            'user_id' => $client->id,
            'client_user_id' => $client->id,
            'name' => 'test',
            'format' => 'json',
            'preset' => 'test',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $preset = $this->makePreset();
        $preset->writeToStream(fopen('php://memory', 'w'), $export);

        $byId = collect($preset->collected)->keyBy('id');

        $this->assertSame(70.00, $byId[$p1->id]['price']);
        $this->assertSame(5, $byId[$p1->id]['stock']);

        $this->assertSame(200.00, $byId[$p2->id]['price']);
        $this->assertSame(12, $byId[$p2->id]['stock']);

        $this->assertSame(300.00, $byId[$p3->id]['price']);
        $this->assertSame(0, $byId[$p3->id]['stock']);
    }

    public function test_chunk_query_count_does_not_grow_with_catalog_size(): void
    {
        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $warehouse->id, 'type' => 'primary'],
        ]);

        $client = User::factory()->create([
            'erp_id' => 'partner-batch-grow',
            'region_id' => $region->id,
        ]);

        $export = ProductExport::create([
            'user_id' => $client->id,
            'client_user_id' => $client->id,
            'name' => 'test',
            'format' => 'json',
            'preset' => 'test',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $smallCount = $this->countQueriesForCatalog($export, 5, $warehouse->id);
        $largeCount = $this->countQueriesForCatalog($export, 50, $warehouse->id);

        // Все 50 товаров умещаются в один чанк (CHUNK_SIZE=500), значит число запросов
        // должно совпадать с каталогом из 5 товаров. Раньше из-за N+1 на цены/остатки
        // разница была бы ~45 (90 без учёта prices).
        $this->assertSame(
            $smallCount,
            $largeCount,
            "Число запросов должно быть константным внутри одного чанка, было: 5={$smallCount}, 50={$largeCount}",
        );
    }

    private function countQueriesForCatalog(ProductExport $export, int $count, int $warehouseId): int
    {
        Product::query()->delete();

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $product = Product::create([
                'name' => "p{$i}",
                'base_price' => 100,
                'external_id' => "pb-grow-{$count}-{$i}",
            ]);
            $rows[] = ['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => 1];
        }
        DB::table('product_warehouse')->insert($rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $preset = $this->makePreset();
            $preset->writeToStream(fopen('php://memory', 'w'), $export);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}
