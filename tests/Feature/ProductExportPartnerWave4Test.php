<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductExport;
use App\Services\ProductExport\Fields\BarcodeField;
use App\Services\ProductExport\Fields\TnvedField;
use App\Services\ProductExport\Fields\UrlField;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Волна 4: реальные источники данных + новые модификаторы.
 *  - BarcodeField fallback на barcodes-pivot,
 *  - UrlField fallback на /products/{slug},
 *  - TnvedField теперь читает hs_code,
 *  - multi_value-модификатор понимает source_separator,
 *  - numeric-модификатор integer_if_whole,
 *  - date-модификатор format,
 *  - CreatedAtField получил modifierType=date.
 */
class ProductExportPartnerWave4Test extends TestCase
{
    use RefreshDatabase;

    public function test_barcode_field_falls_back_to_pivot(): void
    {
        // В products.barcode пусто, но в pivot есть запись
        $product = Product::factory()->create(['barcode' => null]);
        $product->barcodes()->create(['barcode' => '4607123456789']);

        $field = new BarcodeField;
        $value = $field->getValue($product->fresh(['barcodes']));

        $this->assertSame('4607123456789', $value);
    }

    public function test_barcode_field_prefers_direct_column(): void
    {
        $product = Product::factory()->create(['barcode' => '111111']);
        $product->barcodes()->create(['barcode' => '222222']);

        $field = new BarcodeField;
        $value = $field->getValue($product->fresh(['barcodes']));

        // Прямое значение приоритетнее pivot
        $this->assertSame('111111', $value);
    }

    public function test_barcode_field_returns_null_when_no_barcodes(): void
    {
        $product = Product::factory()->create(['barcode' => null]);

        $field = new BarcodeField;
        $value = $field->getValue($product->fresh(['barcodes']));

        $this->assertNull($value);
    }

    public function test_url_field_falls_back_to_generated_url(): void
    {
        $product = Product::factory()->create([
            'url' => null,
            'slug' => 'test-product-slug',
        ]);

        $field = new UrlField;
        $value = $field->getValue($product);

        $this->assertSame(url('/products/test-product-slug'), $value);
    }

    public function test_url_field_prefers_direct_column(): void
    {
        $product = Product::factory()->create([
            'url' => 'https://example.com/custom',
            'slug' => 'whatever',
        ]);

        $field = new UrlField;
        $this->assertSame('https://example.com/custom', $field->getValue($product));
    }

    public function test_tnved_field_reads_hs_code_column(): void
    {
        $product = Product::factory()->create(['hs_code' => '8418102000']);

        $field = new TnvedField;
        $this->assertSame('8418102000', $field->getValue($product));
    }

    public function test_multi_value_modifier_supports_source_separator(): void
    {
        // Эмуляция: подаём строку "А / Б / В" через source_separator=slash и
        // ожидаем "А/Б/В" на выходе.
        $service = app(ProductExportService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyMultiValueModifier');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'А / Б / В', [
            'source_separator' => 'slash',
            'separator' => 'slash_tight',
        ]);
        $this->assertSame('А/Б/В', $result);

        // По умолчанию source_separator=comma — обратная совместимость
        $result2 = $method->invoke($service, '111, 222, 333', [
            'separator' => 'pipe',
        ]);
        $this->assertSame('111 | 222 | 333', $result2);
    }

    public function test_numeric_modifier_integer_if_whole(): void
    {
        $service = app(ProductExportService::class);
        $product = Product::factory()->create(['base_price' => 980.00]);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'base_price', 'modifiers' => ['integer_if_whole' => true]],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();
        $this->assertSame(980, $row['base_price']);

        // А вот 980.50 не должно округлиться
        $product2 = Product::factory()->create(['base_price' => 980.50]);
        $export2 = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'base_price', 'modifiers' => ['integer_if_whole' => true]],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product2->id]],
        ]);
        $row2 = $service->fetchData($export2, 1)->first();
        $this->assertSame(980.5, $row2['base_price']);
    }

    public function test_date_modifier_formats_carbon(): void
    {
        $service = app(ProductExportService::class);

        $product = Product::factory()->create();
        // Принудительно ставим conkrete дату
        $product->created_at = '2026-04-08 13:21:49';
        $product->save();

        $export = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'created_at', 'modifiers' => ['format' => 'd.m.Y H:i:s']],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();
        $this->assertSame('08.04.2026 13:21:49', $row['created_at']);
    }

    public function test_date_field_modifier_type_is_date(): void
    {
        $field = new \App\Services\ProductExport\Fields\CreatedAtField;
        $this->assertSame('date', $field->modifierType());
    }

    public function test_category_path_field_modifier_type_is_multi_value(): void
    {
        $field = new \App\Services\ProductExport\Fields\CategoryPathField;
        $this->assertSame('multi_value', $field->modifierType());
    }
}
