<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductQueryScopesTest extends TestCase
{
    use RefreshDatabase;

    // ─── scopeSearch ─────────────────────────────────────────

    public function test_scope_search_finds_by_name(): void
    {
        $hit = Product::factory()->create(['name' => 'Красное платье']);
        $miss = Product::factory()->create(['name' => 'Синий ремень']);

        $results = Product::query()->search('платье')->pluck('id');

        $this->assertTrue($results->contains($hit->id));
        $this->assertFalse($results->contains($miss->id));
    }

    public function test_scope_search_finds_by_sku(): void
    {
        $hit = Product::factory()->create(['sku' => 'ABC-123']);
        $miss = Product::factory()->create(['sku' => 'XYZ-999']);

        $results = Product::query()->search('abc-123')->pluck('id');

        $this->assertTrue($results->contains($hit->id));
        $this->assertFalse($results->contains($miss->id));
    }

    public function test_scope_search_finds_by_barcode(): void
    {
        $hit = Product::factory()->create(['barcode' => '4607001234567']);
        $miss = Product::factory()->create(['barcode' => '9999999999999']);

        $results = Product::query()->search('4607001234567')->pluck('id');

        $this->assertTrue($results->contains($hit->id));
        $this->assertFalse($results->contains($miss->id));
    }

    // ─── scopeInBrands ───────────────────────────────────────

    public function test_scope_in_brands_filters_correctly(): void
    {
        $brand1 = Brand::factory()->create();
        $brand2 = Brand::factory()->create();

        $hit = Product::factory()->create(['brand_id' => $brand1->id]);
        $miss = Product::factory()->create(['brand_id' => $brand2->id]);

        $results = Product::inBrands([$brand1->id])->pluck('id');

        $this->assertTrue($results->contains($hit->id));
        $this->assertFalse($results->contains($miss->id));
    }

    public function test_scope_in_brands_empty_array_returns_all(): void
    {
        Product::factory()->count(3)->create();

        $results = Product::inBrands([])->get();

        $this->assertCount(3, $results);
    }

    // ─── scopeByPrice ────────────────────────────────────────

    public function test_scope_by_price_min(): void
    {
        $cheap = Product::factory()->create(['base_price' => 50]);
        $expensive = Product::factory()->create(['base_price' => 500]);

        $results = Product::byPrice(100)->pluck('id');

        $this->assertFalse($results->contains($cheap->id));
        $this->assertTrue($results->contains($expensive->id));
    }

    public function test_scope_by_price_max(): void
    {
        $cheap = Product::factory()->create(['base_price' => 50]);
        $expensive = Product::factory()->create(['base_price' => 500]);

        $results = Product::byPrice(null, 100)->pluck('id');

        $this->assertTrue($results->contains($cheap->id));
        $this->assertFalse($results->contains($expensive->id));
    }

    public function test_scope_by_price_range(): void
    {
        $low = Product::factory()->create(['base_price' => 10]);
        $mid = Product::factory()->create(['base_price' => 150]);
        $high = Product::factory()->create(['base_price' => 1000]);

        $results = Product::byPrice(100, 500)->pluck('id');

        $this->assertFalse($results->contains($low->id));
        $this->assertTrue($results->contains($mid->id));
        $this->assertFalse($results->contains($high->id));
    }

    // ─── scopeInSale ─────────────────────────────────────────

    public function test_scope_in_sale_filters_products_with_individual_prices(): void
    {
        $user = User::factory()->create(['erp_id' => 'test-partner-001']);
        $this->actingAs($user);

        $withPrice = Product::factory()->create(['external_id' => 'prod-ext-001']);
        $withoutPrice = Product::factory()->create(['external_id' => 'prod-ext-002']);
        $warehouse = \App\Models\Warehouse::factory()->create(['external_id' => 'wh-001']);

        // v7.1: Создаём индивидуальную цену с числовыми ID
        IndividualPrice::create([
            'partner_id' => $user->id,
            'product_id' => $withPrice->id,
            'warehouse_id' => $warehouse->id,
            'price' => 80.00,
        ]);

        $results = Product::inSale(true)->pluck('id');

        $this->assertTrue($results->contains($withPrice->id));
        $this->assertFalse($results->contains($withoutPrice->id));
    }

    // ─── scopeInFavourites ───────────────────────────────────

    public function test_scope_in_favourites_filters_by_user(): void
    {
        $user = User::factory()->create();
        $fav = Product::factory()->create();
        $notFav = Product::factory()->create();

        $fav->favoritedByUsers()->attach($user);

        $results = Product::inFavourites($user->id)->pluck('id');

        $this->assertTrue($results->contains($fav->id));
        $this->assertFalse($results->contains($notFav->id));
    }

    // ─── scopeInCategory ─────────────────────────────────────

    public function test_scope_in_category_without_descendants(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        Category::fixTree();

        $inParent = Product::factory()->create(['category_id' => $parent->id]);
        $inChild = Product::factory()->create(['category_id' => $child->id]);

        $results = Product::inCategory($parent->id, false)->pluck('id');

        $this->assertTrue($results->contains($inParent->id));
        $this->assertFalse($results->contains($inChild->id));
    }

    public function test_scope_in_category_with_descendants(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        Category::fixTree();

        $inParent = Product::factory()->create(['category_id' => $parent->id]);
        $inChild = Product::factory()->create(['category_id' => $child->id]);
        $other = Product::factory()->create(['category_id' => Category::factory()->create()->id]);

        $results = Product::inCategory($parent->id, true)->pluck('id');

        $this->assertTrue($results->contains($inParent->id));
        $this->assertTrue($results->contains($inChild->id));
        $this->assertFalse($results->contains($other->id));
    }

    // ─── scopeActive (заглушка) ──────────────────────────────

    public function test_scope_active_returns_all(): void
    {
        Product::factory()->count(5)->create();

        $this->assertCount(5, Product::active()->get());
    }

    // ─── scopeInCollections ──────────────────────────────────

    public function test_scope_in_collections_filters_correctly(): void
    {
        $selection = ProductSelection::factory()->create(['slug' => 'test-selection']);
        $hit = Product::factory()->create();
        $miss = Product::factory()->create();

        $hit->productSelections()->attach($selection);

        $results = Product::inCollections([$selection->id])->pluck('id');

        $this->assertTrue($results->contains($hit->id));
        $this->assertFalse($results->contains($miss->id));
    }

    public function test_scope_in_collections_empty_array_returns_all(): void
    {
        Product::factory()->count(3)->create();

        $this->assertCount(3, Product::inCollections([])->get());
    }

    // ─── scopeByAttributes ──────────────────────────────────

    public function test_scope_by_attributes_or_logic(): void
    {
        $attr = Attribute::create(['name' => 'Цвет', 'slug' => 'color', 'type' => 'select']);
        $red = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Красный']);
        $blue = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Синий']);
        $green = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Зелёный']);

        $p1 = Product::factory()->create();
        $p2 = Product::factory()->create();
        $p3 = Product::factory()->create();

        $p1->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $red->id]);
        $p2->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $blue->id]);
        $p3->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $green->id]);

        // OR: красный ИЛИ синий
        $results = Product::byAttributes([$red->id, $blue->id], true)->pluck('id');

        $this->assertTrue($results->contains($p1->id));
        $this->assertTrue($results->contains($p2->id));
        $this->assertFalse($results->contains($p3->id));
    }

    public function test_scope_by_attributes_empty_returns_all(): void
    {
        Product::factory()->count(3)->create();

        $this->assertCount(3, Product::byAttributes([])->get());
    }

    // ─── scopeInStock ────────────────────────────────────────

    public function test_scope_in_stock_null_region_returns_all(): void
    {
        Product::factory()->count(3)->create();

        $this->assertCount(3, Product::inStock('instock', null)->get());
    }
}
