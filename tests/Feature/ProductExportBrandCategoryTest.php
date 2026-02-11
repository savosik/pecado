<?php

namespace Tests\Feature;

use App\Enums\BrandCategory;
use App\Enums\ExportFormat;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductExportBrandCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_category_field_returns_label_string(): void
    {
        $brand = Brand::factory()->create(['category' => BrandCategory::Own]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        $field = new \App\Services\ProductExport\Fields\BrandCategoryField;
        $value = $field->getValue($product->load('brand'));

        $this->assertIsString($value);
        $this->assertEquals('Собственные бренды', $value);
    }

    public function test_brand_category_field_returns_null_when_no_brand(): void
    {
        $product = Product::factory()->create(['brand_id' => null]);

        $field = new \App\Services\ProductExport\Fields\BrandCategoryField;
        $value = $field->getValue($product);

        $this->assertNull($value);
    }

    public function test_brand_category_field_returns_correct_label_for_each_category(): void
    {
        $cases = [
            [BrandCategory::Own, 'Собственные бренды'],
            [BrandCategory::Other, 'Прочие бренды'],
            [BrandCategory::Liquidation, 'Ликвидация'],
        ];

        foreach ($cases as [$category, $expectedLabel]) {
            $brand = Brand::factory()->create(['category' => $category]);
            $product = Product::factory()->create(['brand_id' => $brand->id]);

            $field = new \App\Services\ProductExport\Fields\BrandCategoryField;
            $value = $field->getValue($product->load('brand'));

            $this->assertEquals($expectedLabel, $value, "Failed for category {$category->value}");
        }
    }

    public function test_csv_export_does_not_fail_with_brand_category(): void
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create(['category' => BrandCategory::Own]);
        Product::factory()->create(['brand_id' => $brand->id]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'name' => 'Test Export',
            'format' => ExportFormat::CSV,
            'fields' => ['brand.category'],
            'filters' => [],
            'is_active' => true,
        ]);

        $service = app(ProductExportService::class);
        $response = $service->generate($export);

        $this->assertEquals(200, $response->getStatusCode());

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('Собственные бренды', $content);
        $this->assertStringNotContainsString('could not be converted to string', $content);
    }
}
