<?php

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\Presets\CustomFieldsPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на потоковую запись JSON в CustomFieldsPreset.
 *
 * Раньше writeJson() копил весь массив в памяти и делал один fwrite() в конце,
 * что на каталоге ~5к товаров × 250 полей упирало пик в 260+ МБ (рядом с
 * --memory=256 у worker'а). Здесь проверяем что результат валиден,
 * содержит все товары, и память не растёт линейно с количеством строк.
 */
class CustomFieldsPresetJsonStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_streamed_json_is_valid_and_contains_all_products(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(50)->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Stream JSON test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'id', 'label' => 'id'],
                ['key' => 'name', 'label' => 'name'],
                ['key' => 'sku', 'label' => 'sku'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, 'Stream output is not valid JSON: '.json_last_error_msg());
        $this->assertIsArray($decoded);
        $this->assertCount(50, $decoded);

        foreach ($decoded as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('sku', $row);
        }
    }

    public function test_streamed_json_uses_custom_label_as_key(): void
    {
        $user = User::factory()->create();
        Product::factory()->create(['name' => 'Test Product', 'sku' => 'TST-1']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Label as key test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'name', 'label' => 'product_title'],
                ['key' => 'sku', 'label' => 'article'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertCount(1, $decoded);

        $row = $decoded[0];
        $this->assertArrayHasKey('product_title', $row, 'JSON-ключ должен быть label, а не технический key');
        $this->assertArrayHasKey('article', $row);
        $this->assertArrayNotHasKey('name', $row);
        $this->assertArrayNotHasKey('sku', $row);
        $this->assertSame('Test Product', $row['product_title']);
        $this->assertSame('TST-1', $row['article']);
    }

    public function test_streamed_json_handles_empty_dataset(): void
    {
        $user = User::factory()->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Empty stream test',
            'format' => ExportFormat::JSON,
            'fields' => [['key' => 'id', 'label' => 'id']],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertSame([], $decoded, 'Пустой JSON должен парситься как []');
    }

    public function test_streamed_json_returns_native_array_for_barcodes(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $product->barcodes()->create(['barcode' => '111']);
        $product->barcodes()->create(['barcode' => '222']);
        $product->barcodes()->create(['barcode' => '333']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Barcodes array test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'id', 'label' => 'id'],
                ['key' => 'barcodes.barcode', 'label' => 'barcodes'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertCount(1, $decoded);
        $row = $decoded[0];
        $this->assertIsArray($row['barcodes'], 'multi-value поле должно быть массивом в JSON');
        $this->assertEqualsCanonicalizing(['111', '222', '333'], $row['barcodes']);
    }

    public function test_streamed_json_returns_object_array_for_warehouses_quantity(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $msk = \App\Models\Warehouse::factory()->create(['name' => 'Москва Основной']);
        $tmn = \App\Models\Warehouse::factory()->create(['name' => 'Тюмень Основной']);
        $product->warehouses()->attach([
            $msk->id => ['quantity' => 7],
            $tmn->id => ['quantity' => 12],
        ]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Warehouses object test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'warehouses.pivot.quantity', 'label' => 'warehouses_stocks'],
                ['key' => 'warehouses.name', 'label' => 'warehouses_names'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertCount(1, $decoded);
        $row = $decoded[0];

        $this->assertIsArray($row['warehouses_stocks']);
        $this->assertCount(2, $row['warehouses_stocks']);
        $stocks = collect($row['warehouses_stocks'])->keyBy('id')->all();
        $this->assertSame('Москва Основной', $stocks[$msk->id]['name']);
        $this->assertSame(7, $stocks[$msk->id]['quantity']);
        $this->assertSame('Тюмень Основной', $stocks[$tmn->id]['name']);
        $this->assertSame(12, $stocks[$tmn->id]['quantity']);

        $this->assertIsArray($row['warehouses_names']);
        $names = collect($row['warehouses_names'])->keyBy('id')->all();
        $this->assertSame('Москва Основной', $names[$msk->id]['name']);
    }

    public function test_streamed_json_multi_value_modifier_is_noop_in_native(): void
    {
        // Модификатор separator=pipe должен игнорироваться в JSON (там массив).
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $product->barcodes()->create(['barcode' => '111']);
        $product->barcodes()->create(['barcode' => '222']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Multi-value modifier noop test',
            'format' => ExportFormat::JSON,
            'fields' => [
                [
                    'key' => 'barcodes.barcode',
                    'label' => 'barcodes',
                    'modifiers' => ['separator' => 'pipe'],
                ],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $row = $decoded[0];

        $this->assertIsArray($row['barcodes'], 'separator-модификатор не должен склеивать массив в JSON');
        $this->assertEqualsCanonicalizing(['111', '222'], $row['barcodes']);
    }

    public function test_streamed_json_substring_modifier_skips_arrays(): void
    {
        // substring не должен попытаться обрезать массив.
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $product->barcodes()->create(['barcode' => '1234567890']);
        $product->barcodes()->create(['barcode' => '0987654321']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Substring noop on array test',
            'format' => ExportFormat::JSON,
            'fields' => [
                [
                    'key' => 'barcodes.barcode',
                    'label' => 'barcodes',
                    'modifiers' => ['substring_length' => 3],
                ],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $row = $decoded[0];

        $this->assertIsArray($row['barcodes']);
        $this->assertEqualsCanonicalizing(['1234567890', '0987654321'], $row['barcodes']);
    }

    public function test_streamed_json_scalar_fields_unchanged(): void
    {
        // Backward-compat: скалярные поля (name, sku) НЕ становятся массивами.
        $user = User::factory()->create();
        Product::factory()->create(['name' => 'Тест', 'sku' => 'SKU-1']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Scalar fields test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'name', 'label' => 'name'],
                ['key' => 'sku', 'label' => 'sku'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $row = $decoded[0];

        $this->assertIsString($row['name']);
        $this->assertSame('Тест', $row['name']);
        $this->assertSame('SKU-1', $row['sku']);
    }

    public function test_streamed_json_certificates_is_flat_string_array(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $cert1 = \App\Models\Certificate::create(['name' => 'Сертификат А', 'type' => 'conformity']);
        $cert2 = \App\Models\Certificate::create(['name' => 'Сертификат Б', 'type' => 'conformity']);
        $product->certificates()->attach([$cert1->id, $cert2->id]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Certificates flat list test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'certificates.name', 'label' => 'certificates'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $row = $decoded[0];

        $this->assertIsArray($row['certificates']);
        $this->assertEqualsCanonicalizing(['Сертификат А', 'Сертификат Б'], $row['certificates']);
    }

    public function test_streamed_json_csv_still_returns_strings_for_multi_value(): void
    {
        // Backward-compat для CSV: должно по-прежнему отдавать "111, 222" строкой.
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $product->barcodes()->create(['barcode' => '111']);
        $product->barcodes()->create(['barcode' => '222']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'CSV string test',
            'format' => ExportFormat::CSV,
            'fields' => [
                ['key' => 'barcodes.barcode', 'label' => 'barcodes'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('111, 222', $content, 'CSV должен сохранять CSV-строку для multi-value');
        $this->assertStringNotContainsString('Array', $content);
    }

    public function test_streamed_json_memory_does_not_scale_with_rows(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(500)->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Memory bound test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'id', 'label' => 'id'],
                ['key' => 'name', 'label' => 'name'],
                ['key' => 'description', 'label' => 'description'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        gc_collect_cycles();
        $before = memory_get_usage();

        $stream = fopen('php://memory', 'w+');
        $peak = memory_get_peak_usage();
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        $peakAfter = memory_get_peak_usage();
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $delta = $peakAfter - $peak;
        $decoded = json_decode($content, true);
        $this->assertCount(500, $decoded);

        // Sanity check: пик должен быть ниже 50 МБ для 500 товаров×3 поля.
        // Если writeJson() снова начнёт копить весь массив — пик легко
        // улетит за пределы chunk_size×per_row_bytes.
        $this->assertLessThan(50 * 1024 * 1024, $delta, "Память подскочила на ".round($delta / 1024 / 1024, 1)." МБ — JSON может снова копить весь массив");
    }
}
