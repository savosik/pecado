<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Срок годности («Срок годности (годен до)», date-time атрибут из 1С) должен
 * попадать в сериализацию товара для каталога/карточки/QuickView как поле
 * shelf_life (ISO-дата) — фронт рисует из него бейдж «до MM/YY».
 * Атрибут опознаётся по config('catalog.shelf_life_attribute_slug').
 */
class ProductShelfLifeExposureTest extends TestCase
{
    use RefreshDatabase;

    private function makeShelfLifeAttribute(): Attribute
    {
        return Attribute::create([
            'name' => 'Срок годности (годен до)',
            'slug' => config('catalog.shelf_life_attribute_slug'),
            'type' => 'date-time',
            'is_active' => true,
        ]);
    }

    public function test_shelf_life_is_exposed_as_iso_date(): void
    {
        $attr = $this->makeShelfLifeAttribute();
        $product = Product::factory()->create();
        $product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'datetime_value' => '2029-02-01 00:00:00',
        ]);

        $product->load(ProductQueryService::productEagerLoads());
        $arr = ProductQueryService::productToArray($product);

        $this->assertSame('2029-02-01', $arr['shelf_life']);
    }

    public function test_shelf_life_is_null_when_not_set(): void
    {
        $product = Product::factory()->create();
        $product->load(ProductQueryService::productEagerLoads());

        $this->assertArrayHasKey('shelf_life', ProductQueryService::productToArray($product));
        $this->assertNull(ProductQueryService::productToArray($product)['shelf_life']);
    }

    public function test_shelf_life_ignores_other_date_time_attributes(): void
    {
        // «Дата производства» — тоже date-time, но не срок годности: не должна протечь.
        $other = Attribute::create([
            'name' => 'Дата производства',
            'slug' => 'data-proizvodstva',
            'type' => 'date-time',
            'is_active' => true,
        ]);
        $product = Product::factory()->create();
        $product->attributeValues()->create([
            'attribute_id' => $other->id,
            'datetime_value' => '2024-05-01 00:00:00',
        ]);

        $product->load(ProductQueryService::productEagerLoads());

        $this->assertNull(ProductQueryService::productToArray($product)['shelf_life']);
    }
}
