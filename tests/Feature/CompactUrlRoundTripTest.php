<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompactUrlRoundTripTest extends TestCase
{
    use RefreshDatabase;

    // ─── Compact → Full round-trip ──────────────────────────

    public function test_compact_brand_alias_returns_same_result_as_full_param(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->count(3)->create(['brand_id' => $brand->id]);
        Product::factory()->count(2)->create(); // другой бренд

        // Compact: b[]
        $compactResponse = $this->getJson('/api/catalog/products?b[]='.$brand->id);
        // Full: brand_ids[]
        $fullResponse = $this->getJson('/api/catalog/products?brand_ids[]='.$brand->id);

        $compactResponse->assertOk();
        $fullResponse->assertOk();

        $this->assertEquals(
            $compactResponse->json('meta.total'),
            $fullResponse->json('meta.total'),
            'Compact b[] и full brand_ids[] должны возвращать одинаковое количество товаров'
        );

        $this->assertEquals(
            collect($compactResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            collect($fullResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            'Compact b[] и full brand_ids[] должны возвращать одни и те же товары'
        );
    }

    public function test_compact_category_alias_returns_same_result_as_full_param(): void
    {
        $cat = Category::factory()->create();
        Product::factory()->count(4)->create(['category_id' => $cat->id]);
        Product::factory()->count(1)->create();

        // Compact: c[]
        $compactResponse = $this->getJson('/api/catalog/products?c[]='.$cat->id.'&include_descendants=0');
        // Full: category_ids[]
        $fullResponse = $this->getJson('/api/catalog/products?category_ids[]='.$cat->id.'&include_descendants=0');

        $compactResponse->assertOk();
        $fullResponse->assertOk();

        $this->assertEquals(
            $compactResponse->json('meta.total'),
            $fullResponse->json('meta.total'),
            'Compact c[] и full category_ids[] должны возвращать одинаковое количество товаров'
        );

        $this->assertEquals(
            collect($compactResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            collect($fullResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            'Compact c[] и full category_ids[] должны возвращать одни и те же товары'
        );
    }

    public function test_compact_sort_alias_returns_correctly_sorted_data(): void
    {
        Product::factory()->create(['base_price' => 500]);
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 1000]);

        // Compact: s=price_asc
        $compactResponse = $this->getJson('/api/catalog/products?s=price_asc');
        // Full: sort=price_asc
        $fullResponse = $this->getJson('/api/catalog/products?sort=price_asc');

        $compactResponse->assertOk();
        $fullResponse->assertOk();

        $compactPrices = collect($compactResponse->json('data'))->pluck('base_price')->toArray();
        $fullPrices = collect($fullResponse->json('data'))->pluck('base_price')->toArray();

        $this->assertEquals($compactPrices, $fullPrices, 'Compact s= и full sort= должны возвращать одинаковую сортировку');

        // Проверяем что сортировка действительно по возрастанию
        $sorted = $compactPrices;
        sort($sorted);
        $this->assertEquals($sorted, $compactPrices, 'Товары должны быть отсортированы по возрастанию цены');
    }

    public function test_compact_per_page_alias(): void
    {
        Product::factory()->count(15)->create();

        // Compact: pp=10
        $compactResponse = $this->getJson('/api/catalog/products?pp=10');
        // Full: per_page=10
        $fullResponse = $this->getJson('/api/catalog/products?per_page=10');

        $compactResponse->assertOk()->assertJsonPath('meta.per_page', 10)->assertJsonCount(10, 'data');
        $fullResponse->assertOk()->assertJsonPath('meta.per_page', 10)->assertJsonCount(10, 'data');
    }

    public function test_compact_page_alias(): void
    {
        Product::factory()->count(25)->create();

        // Compact: p=2 (с per_page = 20 по умолчанию)
        $compactResponse = $this->getJson('/api/catalog/products?p=2');
        // Full: page=2
        $fullResponse = $this->getJson('/api/catalog/products?page=2');

        $compactResponse->assertOk()->assertJsonPath('meta.current_page', 2);
        $fullResponse->assertOk()->assertJsonPath('meta.current_page', 2);

        $this->assertEquals(
            collect($compactResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            collect($fullResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            'Compact p= и full page= должны возвращать одни и те же товары'
        );
    }

    public function test_compact_attribute_values_alias(): void
    {
        $attr = Attribute::create([
            'name' => 'Цвет', 'slug' => 'color',
            'type' => 'select', 'is_filterable' => true, 'sort_order' => 0,
        ]);
        $val = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Красный', 'sort_order' => 0]);

        $product = Product::factory()->create();
        $product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'attribute_value_id' => $val->id,
        ]);

        Product::factory()->count(2)->create(); // без атрибута

        // Compact: fv[]
        $compactResponse = $this->getJson('/api/catalog/products?fv[]='.$val->id);
        // Full: attribute_value_ids[]
        $fullResponse = $this->getJson('/api/catalog/products?attribute_value_ids[]='.$val->id);

        $compactResponse->assertOk();
        $fullResponse->assertOk();

        $this->assertEquals(
            $compactResponse->json('meta.total'),
            $fullResponse->json('meta.total'),
            'Compact fv[] и full attribute_value_ids[] должны возвращать одинаковое количество товаров'
        );
    }

    public function test_compact_collection_alias_returns_same_result_as_full_param(): void
    {
        $selection = ProductSelection::factory()->create();
        $p1 = Product::factory()->create();
        $p2 = Product::factory()->create();
        $p3 = Product::factory()->create();

        // Привязываем 2 товара к подборке
        $selection->products()->attach([$p1->id, $p2->id]);

        // Compact: cl[]
        $compactResponse = $this->getJson('/api/catalog/products?cl[]='.$selection->id);
        // Full: collection_ids[]
        $fullResponse = $this->getJson('/api/catalog/products?collection_ids[]='.$selection->id);

        $compactResponse->assertOk();
        $fullResponse->assertOk();

        $this->assertEquals(
            $compactResponse->json('meta.total'),
            $fullResponse->json('meta.total'),
            'Compact cl[] и full collection_ids[] должны возвращать одинаковое количество товаров'
        );

        $this->assertEquals(
            collect($compactResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            collect($fullResponse->json('data'))->pluck('id')->sort()->values()->toArray(),
            'Compact cl[] и full collection_ids[] должны возвращать одни и те же товары'
        );

        $this->assertEquals(2, $compactResponse->json('meta.total'));
    }

    // ─── Дефолтные значения ─────────────────────────────────

    public function test_defaults_work_without_explicit_params(): void
    {
        Product::factory()->count(5)->create();

        $response = $this->getJson('/api/catalog/products');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 5);
    }

    // ─── Комбинация compact-параметров ──────────────────────

    public function test_combined_compact_params(): void
    {
        $brand = Brand::factory()->create();
        $cat = Category::factory()->create();

        Product::factory()->count(3)->create([
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
            'base_price' => 500,
        ]);
        Product::factory()->count(2)->create([
            'brand_id' => $brand->id,
            'base_price' => 100,
        ]);
        Product::factory()->count(1)->create([
            'category_id' => $cat->id,
            'base_price' => 2000,
        ]);

        // Compact: b[] + c[] + price_min + s=price_desc + pp=10
        $response = $this->getJson(
            '/api/catalog/products?b[]='.$brand->id.
            '&c[]='.$cat->id.
            '&include_descendants=0'.
            '&price_min=200'.
            '&s=price_desc'.
            '&pp=10'
        );

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 3);

        // Все 3 товара с brand+category и ценой >= 200
        $prices = collect($response->json('data'))->pluck('base_price')->toArray();
        $sorted = $prices;
        rsort($sorted);
        $this->assertEquals($sorted, $prices, 'Товары должны быть отсортированы по убыванию цены');
    }
}
