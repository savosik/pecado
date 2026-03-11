<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    // ─── products ───────────────────────────────────────────

    public function test_products_returns_paginated_json(): void
    {
        Product::factory()->count(3)->create();

        $response = $this->actingAs($this->user)->getJson('/api/catalog/products');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'sku', 'base_price', 'is_favorited'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_products_default_per_page_is_20(): void
    {
        Product::factory()->count(25)->create();

        $response = $this->getJson('/api/catalog/products');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(20, 'data');
    }

    public function test_products_custom_per_page(): void
    {
        Product::factory()->count(15)->create();

        $response = $this->getJson('/api/catalog/products?per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(10, 'data');
    }

    public function test_products_filters_by_brand(): void
    {
        $brand1 = Brand::factory()->create();
        $brand2 = Brand::factory()->create();

        Product::factory()->count(3)->create(['brand_id' => $brand1->id]);
        Product::factory()->count(2)->create(['brand_id' => $brand2->id]);

        $response = $this->getJson('/api/catalog/products?brand_ids[]=' . $brand1->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_products_filters_by_category(): void
    {
        $cat1 = Category::factory()->create();
        $cat2 = Category::factory()->create();

        Product::factory()->count(4)->create(['category_id' => $cat1->id]);
        Product::factory()->count(1)->create(['category_id' => $cat2->id]);

        $response = $this->getJson('/api/catalog/products?category_id=' . $cat1->id . '&include_descendants=0');

        $response->assertOk()
            ->assertJsonPath('meta.total', 4);
    }

    public function test_products_filters_by_price(): void
    {
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 500]);
        Product::factory()->create(['base_price' => 1000]);

        $response = $this->getJson('/api/catalog/products?price_min=200&price_max=600');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_products_sorts_by_price_asc(): void
    {
        Product::factory()->create(['base_price' => 500]);
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 1000]);

        $response = $this->getJson('/api/catalog/products?sort=price_asc');

        $response->assertOk();

        $prices = collect($response->json('data'))->pluck('base_price')->toArray();
        $sorted = $prices;
        sort($sorted);
        $this->assertEquals($sorted, $prices, 'Товары должны быть отсортированы по возрастанию цены');
    }

    public function test_products_sorts_by_price_desc(): void
    {
        Product::factory()->create(['base_price' => 500]);
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 1000]);

        $response = $this->getJson('/api/catalog/products?sort=price_desc');

        $response->assertOk();

        $prices = collect($response->json('data'))->pluck('base_price')->toArray();
        $sorted = $prices;
        rsort($sorted);
        $this->assertEquals($sorted, $prices, 'Товары должны быть отсортированы по убыванию цены');
    }

    public function test_products_compact_params(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->count(2)->create(['brand_id' => $brand->id]);
        Product::factory()->count(3)->create();

        // b → brand_ids (compact URL)
        $response = $this->getJson('/api/catalog/products?b[]=' . $brand->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_products_search(): void
    {
        Product::factory()->create(['name' => 'Alpha product one']);
        Product::factory()->create(['name' => 'Beta product two']);
        Product::factory()->create(['name' => 'Alpha mega three']);

        $response = $this->getJson('/api/catalog/products?q=alpha');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_products_is_favorited_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        // Добавить в избранное через таблицу favorites
        \Illuminate\Support\Facades\DB::table('favorites')->insert([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/catalog/products');

        $response->assertOk();
        $data = $response->json('data');
        $found = collect($data)->firstWhere('id', $product->id);
        $this->assertTrue($found['is_favorited']);
    }

    public function test_products_is_favorited_false_for_guest(): void
    {
        Product::factory()->create();

        $response = $this->getJson('/api/catalog/products');

        $response->assertOk();
        foreach ($response->json('data') as $product) {
            $this->assertFalse($product['is_favorited']);
        }
    }

    public function test_validation_rejects_invalid_sort(): void
    {
        $response = $this->getJson('/api/catalog/products?sort=invalid');

        $response->assertStatus(422);
    }

    public function test_validation_rejects_invalid_per_page(): void
    {
        $response = $this->getJson('/api/catalog/products?per_page=50');

        $response->assertStatus(422);
    }

    // ─── facets ─────────────────────────────────────────────

    public function test_facets_returns_brands_categories_attributes(): void
    {
        $brand = Brand::factory()->create();
        $cat = Category::factory()->create();
        $attr = Attribute::create([
            'name' => 'Цвет', 'slug' => 'color',
            'type' => 'select', 'is_filterable' => true, 'sort_order' => 0,
        ]);
        $val = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Красный', 'sort_order' => 0]);

        $product = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $cat->id]);
        $product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'attribute_value_id' => $val->id,
        ]);

        $response = $this->getJson('/api/catalog/products/facets');

        $response->assertOk()
            ->assertJsonStructure([
                'brands' => ['*' => ['id', 'name', 'slug', 'count']],
                'categories' => ['*' => ['id', 'name', 'slug', 'count']],
                'attributes' => ['*' => ['id', 'name', 'values' => ['*' => ['id', 'value', 'count']]]],
            ]);

        $this->assertEquals($brand->id, $response->json('brands.0.id'));
        $this->assertEquals($cat->id, $response->json('categories.0.id'));
        $this->assertEquals('Цвет', $response->json('attributes.0.name'));
    }

    public function test_facets_respects_filters(): void
    {
        $brand1 = Brand::factory()->create();
        $brand2 = Brand::factory()->create();

        Product::factory()->count(2)->create(['brand_id' => $brand1->id]);
        Product::factory()->count(3)->create(['brand_id' => $brand2->id]);

        // Фильтруем по brand1 — в фасетах категорий должны быть только товары brand1
        $response = $this->getJson('/api/catalog/products/facets?brand_ids[]=' . $brand1->id);

        $response->assertOk();

        // brand1 должен быть в фасетах с count=2
        $facetBrands = collect($response->json('brands'));
        $b1 = $facetBrands->firstWhere('id', $brand1->id);
        $this->assertEquals(2, $b1['count']);
    }

    // ─── price-intervals ────────────────────────────────────

    public function test_price_intervals_returns_min_max_buckets(): void
    {
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 5000]);
        Product::factory()->create(['base_price' => 10000]);

        $response = $this->getJson('/api/catalog/products/price-intervals');

        $response->assertOk()
            ->assertJsonStructure(['min', 'max', 'buckets']);

        $this->assertEquals(100, $response->json('min'));
        $this->assertEquals(10000, $response->json('max'));
        $this->assertNotEmpty($response->json('buckets'));
    }

    public function test_price_intervals_ignores_price_filters(): void
    {
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 5000]);
        Product::factory()->create(['base_price' => 10000]);

        // Даже с price_min/price_max, интервалы должны охватывать полный диапазон
        $response = $this->getJson('/api/catalog/products/price-intervals?price_min=200&price_max=6000');

        $response->assertOk();
        $this->assertEquals(100, $response->json('min'));
        $this->assertEquals(10000, $response->json('max'));
    }

    public function test_price_intervals_empty(): void
    {
        $response = $this->getJson('/api/catalog/products/price-intervals');

        $response->assertOk()
            ->assertJsonPath('min', 0)
            ->assertJsonPath('max', 0)
            ->assertJsonPath('buckets', []);
    }
}
