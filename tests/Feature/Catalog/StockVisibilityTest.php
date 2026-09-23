<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\StockVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Бренды и категории без товаров в наличии (ни на основных складах, ни на предзаказе)
 * не показываются в списках витрины — считается на лету, без ручного выключения.
 */
class StockVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private Warehouse $primary;

    private Warehouse $preorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::create(['name' => 'Москва']);
        $this->primary = Warehouse::factory()->create();
        $this->preorder = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $this->primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $this->region->id, 'warehouse_id' => $this->preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    #[Test]
    public function brands_list_shows_only_brands_with_stock_on_primary_or_preorder(): void
    {
        $onPrimary = Brand::factory()->create(['name' => 'На складе']);
        $onPreorder = Brand::factory()->create(['name' => 'Под заказ']);
        $empty = Brand::factory()->create(['name' => 'Пустой']);
        $zeroQty = Brand::factory()->create(['name' => 'Нулевой остаток']);
        $hiddenOnly = Brand::factory()->create(['name' => 'Только скрытые товары']);

        $this->stock(Product::factory()->create(['brand_id' => $onPrimary->id]), $this->primary, 3);
        $this->stock(Product::factory()->create(['brand_id' => $onPreorder->id]), $this->preorder, 1);
        Product::factory()->create(['brand_id' => $empty->id]);
        $this->stock(Product::factory()->create(['brand_id' => $zeroQty->id]), $this->primary, 0);
        $this->stock(Product::factory()->create(['brand_id' => $hiddenOnly->id, 'hidden' => true]), $this->primary, 5);

        $names = collect($this->getJson('/api/catalog/brands')->assertOk()->json('data'))->pluck('name');

        $this->assertEqualsCanonicalizing(['На складе', 'Под заказ'], $names->all());
    }

    #[Test]
    public function parent_brand_is_shown_when_only_a_child_has_stock_and_empty_children_are_dropped(): void
    {
        $parent = Brand::factory()->create(['name' => 'Родитель']);
        $stockedChild = Brand::factory()->create(['name' => 'Дочерний со складом', 'parent_id' => $parent->id]);
        Brand::factory()->create(['name' => 'Дочерний пустой', 'parent_id' => $parent->id]);
        $emptyParent = Brand::factory()->create(['name' => 'Родитель без остатков']);
        Brand::factory()->create(['name' => 'Его дочерний', 'parent_id' => $emptyParent->id]);

        $this->stock(Product::factory()->create(['brand_id' => $stockedChild->id]), $this->primary, 2);

        $data = collect($this->getJson('/api/catalog/brands')->assertOk()->json('data'));

        $this->assertSame(['Родитель'], $data->pluck('name')->all());
        $this->assertSame(['Дочерний со складом'], collect($data->first()['children'])->pluck('name')->all());
    }

    #[Test]
    public function stock_on_a_warehouse_outside_the_region_does_not_count(): void
    {
        $foreign = Warehouse::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Чужой склад']);
        $this->stock(Product::factory()->create(['brand_id' => $brand->id]), $foreign, 10);

        $this->getJson('/api/catalog/brands')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function categories_tree_shows_stocked_branches_and_hides_empty_ones(): void
    {
        $root = Category::create(['name' => 'Корень', 'slug' => 'root', 'sort' => 1]);
        $stockedChild = Category::create(['name' => 'Ветка со складом', 'slug' => 'stocked', 'parent_id' => $root->id, 'sort' => 1]);
        Category::create(['name' => 'Ветка пустая', 'slug' => 'empty-child', 'parent_id' => $root->id, 'sort' => 2]);
        // sort проставлен, но остатков нет: видимость считается по остаткам, а не по sort.
        Category::create(['name' => 'Пустой корень', 'slug' => 'empty-root', 'sort' => 2]);
        // sort не проставлен (команда ещё не прошла), но остаток есть — показываем.
        $freshRoot = Category::create(['name' => 'Новый корень', 'slug' => 'fresh-root']);

        $this->stock(Product::factory()->create(['category_id' => $stockedChild->id]), $this->preorder, 1);
        $this->stock(Product::factory()->create(['category_id' => $freshRoot->id]), $this->primary, 1);

        $tree = collect($this->getJson('/api/catalog/categories')->assertOk()->json('categories'));

        $this->assertSame(['Корень', 'Новый корень'], $tree->pluck('name')->all());
        $this->assertSame(['Ветка со складом'], collect($tree->first()['children'])->pluck('name')->all());
    }

    #[Test]
    public function root_categories_and_children_endpoints_hide_empty_categories(): void
    {
        $root = Category::create(['name' => 'Корень', 'slug' => 'root']);
        $stocked = Category::create(['name' => 'Со складом', 'slug' => 'stocked', 'parent_id' => $root->id]);
        Category::create(['name' => 'Пустая', 'slug' => 'empty', 'parent_id' => $root->id]);
        Category::create(['name' => 'Пустой корень', 'slug' => 'empty-root']);

        $this->stock(Product::factory()->create(['category_id' => $stocked->id]), $this->primary, 1);

        $user = User::factory()->create(['region_id' => $this->region->id]);

        $this->actingAs($user)->getJson('/api/categories')->assertOk()->assertJsonPath('categories.0.name', 'Корень')->assertJsonCount(1, 'categories');
        $this->actingAs($user)->getJson("/api/categories/{$root->id}")->assertOk()->assertJsonPath('children.0.name', 'Со складом')->assertJsonCount(1, 'children');
    }

    #[Test]
    public function without_regions_lists_are_not_filtered(): void
    {
        DB::table('region_warehouse')->delete();
        Region::query()->delete();

        Brand::factory()->create(['name' => 'Без региона']);
        Category::create(['name' => 'Без региона', 'slug' => 'no-region']);

        $this->getJson('/api/catalog/brands')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/catalog/categories')->assertOk()->assertJsonCount(1, 'categories');
    }

    #[Test]
    public function result_is_cached_per_region_until_forgotten(): void
    {
        $service = app(StockVisibility::class);
        $brand = Brand::factory()->create();

        $this->assertSame([], $service->brandIds($this->region->id));

        $this->stock(Product::factory()->create(['brand_id' => $brand->id]), $this->primary, 1);
        $this->assertSame([], $service->brandIds($this->region->id), 'до сброса кеша отдаётся прежний ответ');

        $service->forget($this->region->id);
        $this->assertSame([$brand->id], $service->brandIds($this->region->id));
    }

    private function stock(Product $product, Warehouse $warehouse, int $qty): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
