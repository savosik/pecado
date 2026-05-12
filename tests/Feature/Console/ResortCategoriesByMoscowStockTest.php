<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Product;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResortCategoriesByMoscowStockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assigns_sort_among_siblings_by_descending_stock(): void
    {
        $moscow = Region::create(['name' => 'Москва']);
        $primary = Warehouse::factory()->create();
        $preorder = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $moscow->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $moscow->id, 'warehouse_id' => $preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $few = Category::create(['name' => 'Мало', 'slug' => 'few']);
        $many = Category::create(['name' => 'Много', 'slug' => 'many']);
        $zero = Category::create(['name' => 'Пусто', 'slug' => 'zero']);

        $this->seedStockedProducts($few, $primary, 1);
        $this->seedStockedProducts($many, $primary, 3);

        $this->artisan('categories:resort-by-moscow-stock')->assertSuccessful();

        $this->assertSame(1, (int) Category::find($many->id)->sort);
        $this->assertSame(2, (int) Category::find($few->id)->sort);
        // Категория без товаров на всю глубину → sort = NULL (скрыта в каталог-панели).
        $this->assertNull(Category::find($zero->id)->sort);
    }

    #[Test]
    public function deep_count_includes_subcategories(): void
    {
        $moscow = Region::create(['name' => 'Москва']);
        $primary = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $moscow->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Корень A: 1 свой товар + 5 в подкатегории (итого 6 в глубину).
        // Корень B: 4 своих товара (итого 4).
        // Ожидаем sort: A = 1, B = 2.
        $rootA = Category::create(['name' => 'A', 'slug' => 'a']);
        $childA = Category::create(['name' => 'A-child', 'slug' => 'a-child', 'parent_id' => $rootA->id]);
        $rootB = Category::create(['name' => 'B', 'slug' => 'b']);

        $this->seedStockedProducts($rootA, $primary, 1);
        $this->seedStockedProducts($childA, $primary, 5);
        $this->seedStockedProducts($rootB, $primary, 4);

        $this->artisan('categories:resort-by-moscow-stock')->assertSuccessful();

        $this->assertSame(1, (int) Category::find($rootA->id)->sort);
        $this->assertSame(2, (int) Category::find($rootB->id)->sort);
        // У ребёнка нет соседей — sort = 1.
        $this->assertSame(1, (int) Category::find($childA->id)->sort);
    }

    #[Test]
    public function preorder_warehouses_contribute_to_stock(): void
    {
        $moscow = Region::create(['name' => 'Москва']);
        $preorder = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $moscow->id, 'warehouse_id' => $preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $a = Category::create(['name' => 'A', 'slug' => 'a']);
        $b = Category::create(['name' => 'B', 'slug' => 'b']);

        $this->seedStockedProducts($a, $preorder, 2);
        $this->seedStockedProducts($b, $preorder, 0);

        $this->artisan('categories:resort-by-moscow-stock')->assertSuccessful();

        $this->assertSame(1, (int) Category::find($a->id)->sort);
        $this->assertNull(Category::find($b->id)->sort);
    }

    #[Test]
    public function hidden_products_are_excluded(): void
    {
        $moscow = Region::create(['name' => 'Москва']);
        $primary = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $moscow->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $a = Category::create(['name' => 'A', 'slug' => 'a']);
        $b = Category::create(['name' => 'B', 'slug' => 'b']);

        // У A — 3 скрытых товара (не считаются); у B — 1 видимый.
        $this->seedStockedProducts($a, $primary, 3, hidden: true);
        $this->seedStockedProducts($b, $primary, 1, hidden: false);

        $this->artisan('categories:resort-by-moscow-stock')->assertSuccessful();

        $this->assertSame(1, (int) Category::find($b->id)->sort);
        // Все товары A скрыты → deep_count = 0 → sort = NULL.
        $this->assertNull(Category::find($a->id)->sort);
    }

    #[Test]
    public function parent_keeps_sort_when_only_children_have_stock(): void
    {
        $moscow = Region::create(['name' => 'Москва']);
        $primary = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $moscow->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $parent = Category::create(['name' => 'Родитель', 'slug' => 'parent']);
        $filledChild = Category::create(['name' => 'С товарами', 'slug' => 'filled', 'parent_id' => $parent->id]);
        $emptyChild = Category::create(['name' => 'Пустой', 'slug' => 'empty', 'parent_id' => $parent->id]);

        $this->seedStockedProducts($filledChild, $primary, 2);

        $this->artisan('categories:resort-by-moscow-stock')->assertSuccessful();

        $this->assertSame(1, (int) Category::find($parent->id)->sort);
        $this->assertSame(1, (int) Category::find($filledChild->id)->sort);
        $this->assertNull(Category::find($emptyChild->id)->sort);
    }

    #[Test]
    public function dry_run_does_not_write_sort(): void
    {
        $moscow = Region::create(['name' => 'Москва']);
        $primary = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            ['region_id' => $moscow->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $a = Category::create(['name' => 'A', 'slug' => 'a']);
        $this->seedStockedProducts($a, $primary, 2);

        $this->artisan('categories:resort-by-moscow-stock', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(Category::find($a->id)->sort);
    }

    #[Test]
    public function missing_region_fails(): void
    {
        $this->artisan('categories:resort-by-moscow-stock', ['--region' => 'Несуществующий'])
            ->assertFailed();
    }

    private function seedStockedProducts(Category $category, Warehouse $warehouse, int $count, bool $hidden = false): void
    {
        for ($i = 0; $i < $count; $i++) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'hidden' => $hidden,
            ]);
            DB::table('product_warehouse')->insert([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
