<?php

namespace Tests\Feature\Services\ProductExport\Presets;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductExport;
use App\Models\ProductModel;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductExport\Presets\GoogleMerchantXmlPreset;
use App\Services\ProductExport\Presets\JsonCatalogPreset;
use App\Services\ProductExport\Presets\OpenCartCsvPreset;
use App\Services\ProductExport\Presets\TildaCsvPreset;
use App\Services\ProductExport\Presets\YmlPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EagerLoadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductExport $export;

    protected function setUp(): void
    {
        parent::setUp();

        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $warehouse->id, 'type' => 'primary'],
        ]);

        $this->user = User::factory()->create([
            'erp_id' => 'partner-eager',
            'region_id' => $region->id,
        ]);

        $model = ProductModel::create(['name' => 'Series-A', 'code' => 'SA-1']);
        for ($i = 0; $i < 3; $i++) {
            $product = Product::create([
                'name' => "p{$i}",
                'base_price' => 100 + $i,
                'external_id' => "ep-{$i}",
                'model_id' => $model->id,
            ]);
            ProductBarcode::create(['product_id' => $product->id, 'barcode' => "B-{$i}"]);
        }

        $this->export = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'eager',
            'format' => 'json',
            'preset' => 'json_catalog',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);
    }

    public function test_lean_preset_skips_barcodes_and_model_queries(): void
    {
        $preset = app(TildaCsvPreset::class);
        $tables = $this->collectQueriedTables($preset);

        $this->assertNotContains('product_barcodes', $tables, 'Tilda не должен SELECT-ить product_barcodes');
        $this->assertNotContains('product_models', $tables, 'Tilda не должен SELECT-ить product_models');
        // Базовый каталог всё равно поднимается из products.
        $this->assertContains('products', $tables);
    }

    public function test_json_catalog_preset_loads_barcodes_and_model(): void
    {
        $preset = app(JsonCatalogPreset::class);
        $tables = $this->collectQueriedTables($preset);

        $this->assertContains('product_barcodes', $tables);
        $this->assertContains('product_models', $tables);
    }

    public function test_yml_preset_loads_barcodes_and_model(): void
    {
        $preset = app(YmlPreset::class);
        $tables = $this->collectQueriedTables($preset);

        $this->assertContains('product_barcodes', $tables);
        $this->assertContains('product_models', $tables);
    }

    public function test_google_preset_loads_barcodes_but_not_model(): void
    {
        $preset = app(GoogleMerchantXmlPreset::class);
        $tables = $this->collectQueriedTables($preset);

        $this->assertContains('product_barcodes', $tables);
        $this->assertNotContains('product_models', $tables);
    }

    public function test_opencart_preset_loads_model_but_not_barcodes(): void
    {
        $preset = app(OpenCartCsvPreset::class);
        $tables = $this->collectQueriedTables($preset);

        $this->assertContains('product_models', $tables);
        $this->assertNotContains('product_barcodes', $tables);
    }

    public function test_lean_preset_does_not_lazy_load_barcodes_during_mapping(): void
    {
        // Дополнительный страховочный тест: проверяем именно отсутствие N+1 на скип.
        // Если бы mapProduct внутри обращался к $product->barcodes без relationLoaded,
        // мы бы увидели один SELECT в product_barcodes на каждый из 3 товаров.
        $preset = app(TildaCsvPreset::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $preset->writeToStream(fopen('php://memory', 'w'), $this->export);
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $barcodeQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'product_barcodes'));
        $modelQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'product_models'));

        $this->assertCount(0, $barcodeQueries);
        $this->assertCount(0, $modelQueries);
    }

    /**
     * @return string[] список уникальных таблиц, к которым были запросы во время writeToStream
     */
    private function collectQueriedTables($preset): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $preset->writeToStream(fopen('php://memory', 'w'), $this->export);
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $tables = [];
        foreach ($log as $entry) {
            if (preg_match_all('/(?:from|join)\s+"([a-z_]+)"/i', $entry['query'], $matches)) {
                foreach ($matches[1] as $t) {
                    $tables[$t] = true;
                }
            }
        }

        return array_keys($tables);
    }
}
