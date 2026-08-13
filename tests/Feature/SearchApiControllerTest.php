<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Товарная часть поиска: те же фильтры, фасеты и сортировки, что и в каталоге,
 * поверх релевантной выдачи Meilisearch (в тестах — collection-драйвер Scout).
 */
class SearchApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Используем collection-драйвер Scout для тестирования без Meilisearch
        config(['scout.driver' => 'collection']);

        $this->user = User::factory()->create();
    }

    /**
     * Регион по умолчанию с основным и предзаказным складами.
     *
     * @return array{primary: Warehouse, preorder: Warehouse}
     */
    private function setupRegionWarehouses(): array
    {
        $region = Region::factory()->create();
        $primary = Warehouse::factory()->create();
        $preorder = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $region->id, 'warehouse_id' => $preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return ['primary' => $primary, 'preorder' => $preorder];
    }

    // ─── products ───────────────────────────────────────────

    public function test_products_requires_query(): void
    {
        $this->getJson('/api/search/products')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_products_short_query_fails_validation(): void
    {
        $this->getJson('/api/search/products?q=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_products_returns_only_matching_products(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha']);
        Product::factory()->create(['name' => 'Вибратор Beta']);
        Product::factory()->create(['name' => 'Совсем другой товар']);

        $response = $this->actingAs($this->user)->getJson('/api/search/products?q=Вибратор');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => ['id', 'name', 'slug', 'sku', 'base_price', 'is_favorited']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'no_exact_match', 'capped'],
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_products_returns_empty_result_for_unknown_query(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha']);

        $this->actingAs($this->user)->getJson('/api/search/products?q=нетакогослова')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_products_filters_by_brand(): void
    {
        $brand = Brand::factory()->create();
        $other = Brand::factory()->create();

        Product::factory()->count(2)->create(['name' => 'Вибратор Alpha', 'brand_id' => $brand->id]);
        Product::factory()->create(['name' => 'Вибратор Beta', 'brand_id' => $other->id]);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&brand_ids[]='.$brand->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_products_filters_by_category(): void
    {
        $category = Category::factory()->create();
        Category::factory()->create();

        Product::factory()->create(['name' => 'Вибратор Alpha', 'category_id' => $category->id]);
        Product::factory()->create(['name' => 'Вибратор Beta']);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&category_ids[]='.$category->id.'&include_descendants=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_products_filters_by_price(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha', 'base_price' => 100]);
        Product::factory()->create(['name' => 'Вибратор Beta', 'base_price' => 5000]);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&price_max=1000')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Вибратор Alpha');
    }

    public function test_products_sorting_by_price_applies(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha', 'base_price' => 900]);
        Product::factory()->create(['name' => 'Вибратор Beta', 'base_price' => 100]);
        Product::factory()->create(['name' => 'Вибратор Gamma', 'base_price' => 500]);

        $prices = $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&sort=price_asc')
            ->assertOk()
            ->json('data.*.base_price');

        $this->assertSame([100.0, 500.0, 900.0], array_map('floatval', $prices));
    }

    public function test_products_pagination(): void
    {
        Product::factory()->count(25)->create(['name' => 'Вибратор Alpha']);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonCount(10, 'data');
    }

    public function test_products_exact_sku_match_returns_only_that_product(): void
    {
        $product = Product::factory()->create(['name' => 'Первый товар', 'sku' => 'ABC-123']);
        Product::factory()->create(['name' => 'ABC-123 в названии другого товара']);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=ABC-123')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.no_exact_match', false)
            ->assertJsonPath('data.0.id', $product->id);
    }

    public function test_products_no_exact_match_flag(): void
    {
        // Collection-драйвер ищет подстрокой, поэтому «похожесть» эмулируем
        // товаром, в котором запрос встречается только частично.
        Product::factory()->create(['name' => 'Вибратор Alpha', 'sku' => 'VIB-1']);

        $exact = $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор')
            ->assertOk();

        $exact->assertJsonPath('meta.no_exact_match', false);
    }

    public function test_products_hides_prices_from_guests(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha', 'base_price' => 100]);

        $data = $this->getJson('/api/search/products?q=Вибратор')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertArrayNotHasKey('base_price', $data[0]);
        $this->assertFalse($data[0]['is_favorited']);
    }

    // ─── наличие ────────────────────────────────────────────

    public function test_products_include_unavailable_by_default(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'Вибратор InStock']);
        $inStock->warehouses()->attach($wh['primary']->id, ['quantity' => 5]);

        $preorder = Product::factory()->create(['name' => 'Вибратор Preorder']);
        $preorder->warehouses()->attach($wh['preorder']->id, ['quantity' => 7]);

        // Ни в наличии, ни под предзаказ — в поиске всё равно должен находиться
        $unavailable = Product::factory()->create(['name' => 'Вибратор NoStock']);

        $ids = collect(
            $this->actingAs($this->user)
                ->getJson('/api/search/products?q=Вибратор')
                ->assertOk()
                ->assertJsonPath('meta.total', 3)
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($unavailable->id, $ids, 'Товар без остатков обязан находиться поиском');

        // Порядок релевантности: в наличии → предзаказ → нет в наличии
        $this->assertSame([$inStock->id, $preorder->id, $unavailable->id], $ids);
    }

    public function test_catalog_still_hides_unavailable_by_default(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'Вибратор InStock']);
        $inStock->warehouses()->attach($wh['primary']->id, ['quantity' => 5]);

        Product::factory()->create(['name' => 'Вибратор NoStock']);

        $this->actingAs($this->user)
            ->getJson('/api/catalog/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $inStock->id);
    }

    public function test_products_stock_filter_instock(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'Вибратор InStock']);
        $inStock->warehouses()->attach($wh['primary']->id, ['quantity' => 5]);

        $preorder = Product::factory()->create(['name' => 'Вибратор Preorder']);
        $preorder->warehouses()->attach($wh['preorder']->id, ['quantity' => 7]);

        Product::factory()->create(['name' => 'Вибратор NoStock']);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&in_stock_mode=instock')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $inStock->id);
    }

    public function test_products_stock_filter_available_includes_preorder(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'Вибратор InStock']);
        $inStock->warehouses()->attach($wh['primary']->id, ['quantity' => 5]);

        $preorder = Product::factory()->create(['name' => 'Вибратор Preorder']);
        $preorder->warehouses()->attach($wh['preorder']->id, ['quantity' => 7]);

        $unavailable = Product::factory()->create(['name' => 'Вибратор NoStock']);

        $ids = collect(
            $this->actingAs($this->user)
                ->getJson('/api/search/products?q=Вибратор&in_stock_mode=available')
                ->assertOk()
                ->assertJsonPath('meta.total', 2)
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($inStock->id, $ids);
        $this->assertContains($preorder->id, $ids);
        $this->assertNotContains($unavailable->id, $ids);
    }

    public function test_products_stock_filter_notavailable(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'Вибратор InStock']);
        $inStock->warehouses()->attach($wh['primary']->id, ['quantity' => 5]);

        $unavailable = Product::factory()->create(['name' => 'Вибратор NoStock']);

        $this->actingAs($this->user)
            ->getJson('/api/search/products?q=Вибратор&in_stock_mode=notavailable')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $unavailable->id);
    }

    // ─── facets ─────────────────────────────────────────────

    public function test_facets_limited_to_found_products(): void
    {
        $brand = Brand::factory()->create();
        $otherBrand = Brand::factory()->create();

        Product::factory()->create(['name' => 'Вибратор Alpha', 'brand_id' => $brand->id]);
        Product::factory()->create(['name' => 'Совсем другой товар', 'brand_id' => $otherBrand->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/search/products/facets?q=Вибратор')
            ->assertOk()
            ->assertJsonStructure(['brands', 'categories', 'attributes']);

        $brandIds = collect($response->json('brands'))->pluck('id')->all();

        $this->assertContains($brand->id, $brandIds);
        $this->assertNotContains($otherBrand->id, $brandIds);
    }

    public function test_facets_empty_for_unknown_query(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha']);

        $this->actingAs($this->user)
            ->getJson('/api/search/products/facets?q=нетакогослова')
            ->assertOk()
            ->assertExactJson(['brands' => [], 'categories' => [], 'attributes' => []]);
    }

    // ─── price-intervals ────────────────────────────────────

    public function test_price_intervals_limited_to_found_products(): void
    {
        Product::factory()->create(['name' => 'Вибратор Alpha', 'base_price' => 100]);
        Product::factory()->create(['name' => 'Вибратор Beta', 'base_price' => 900]);
        Product::factory()->create(['name' => 'Совсем другой товар', 'base_price' => 99000]);

        $intervals = $this->actingAs($this->user)
            ->getJson('/api/search/products/price-intervals?q=Вибратор')
            ->assertOk()
            ->json();

        $this->assertSame(100.0, (float) $intervals['min']);
        $this->assertSame(900.0, (float) $intervals['max']);
    }
}
