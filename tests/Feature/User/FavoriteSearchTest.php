<?php

namespace Tests\Feature\User;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FavoriteSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Region $region;

    private Warehouse $primaryWarehouse;

    private Warehouse $preorderWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::create(['name' => 'Регион тест']);

        $this->primaryWarehouse = Warehouse::create([
            'name' => 'Основной склад',
            'external_id' => (string) Str::uuid(),
        ]);
        $this->preorderWarehouse = Warehouse::create([
            'name' => 'Склад предзаказа',
            'external_id' => (string) Str::uuid(),
        ]);

        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $this->primaryWarehouse->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $this->region->id, 'warehouse_id' => $this->preorderWarehouse->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->user = User::factory()->create(['region_id' => $this->region->id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFavorites(string $queryString = ''): array
    {
        $url = '/favorites'.($queryString !== '' ? '?'.$queryString : '');
        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['favorites']['data'] ?? [];
    }

    private function favorite(Product $product): Favorite
    {
        return Favorite::create(['user_id' => $this->user->id, 'product_id' => $product->id]);
    }

    private function setStock(Product $product, Warehouse $warehouse, int $qty): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function search_by_name(): void
    {
        $matching = Product::factory()->create(['name' => 'Кроссовки Air Max']);
        $other = Product::factory()->create(['name' => 'Прочая обувь']);
        $this->favorite($matching);
        $this->favorite($other);

        $ids = array_column($this->fetchFavorites('search='.urlencode('Air Max')), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_sku(): void
    {
        $matching = Product::factory()->create(['sku' => 'AM90-001']);
        $other = Product::factory()->create(['sku' => 'OTHER']);
        $this->favorite($matching);
        $this->favorite($other);

        $ids = array_column($this->fetchFavorites('search=AM90-001'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_brand(): void
    {
        $brand = Brand::create(['name' => 'AdidasFav', 'slug' => 'adidas-fav-'.Str::random(5)]);
        $matching = Product::factory()->create(['brand_id' => $brand->id]);
        $other = Product::factory()->create();
        $this->favorite($matching);
        $this->favorite($other);

        $ids = array_column($this->fetchFavorites('search=AdidasFav'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_exact_barcode(): void
    {
        $matching = Product::factory()->create();
        ProductBarcode::create(['product_id' => $matching->id, 'barcode' => '4607123456789']);
        $other = Product::factory()->create();
        ProductBarcode::create(['product_id' => $other->id, 'barcode' => '4607999999999']);

        $this->favorite($matching);
        $this->favorite($other);

        $ids = array_column($this->fetchFavorites('search=4607123456789'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function scope_excludes_other_users_favorites(): void
    {
        $product = Product::factory()->create(['name' => 'Виден всем']);
        $other = User::factory()->create(['region_id' => $this->region->id]);

        Favorite::create(['user_id' => $other->id, 'product_id' => $product->id]);

        $ids = array_column($this->fetchFavorites('search=Виден'), 'id');

        $this->assertNotContains($product->id, $ids);
    }

    #[Test]
    public function default_sort_is_added_desc(): void
    {
        $first = Product::factory()->create(['name' => 'Первый']);
        $this->favorite($first)->update(['created_at' => now()->subDays(2)]);

        $second = Product::factory()->create(['name' => 'Второй']);
        $this->favorite($second)->update(['created_at' => now()->subDay()]);

        $third = Product::factory()->create(['name' => 'Третий']);
        $this->favorite($third)->update(['created_at' => now()]);

        $ids = array_column($this->fetchFavorites(), 'id');

        $this->assertSame([$third->id, $second->id, $first->id], $ids);
    }

    #[Test]
    public function sort_by_price_asc(): void
    {
        $cheap = Product::factory()->create(['name' => 'Дешёвый', 'base_price' => 100]);
        $expensive = Product::factory()->create(['name' => 'Дорогой', 'base_price' => 500]);
        $mid = Product::factory()->create(['name' => 'Средний', 'base_price' => 300]);

        $this->favorite($cheap);
        $this->favorite($expensive);
        $this->favorite($mid);

        $ids = array_column($this->fetchFavorites('sort=price_asc'), 'id');

        $this->assertSame([$cheap->id, $mid->id, $expensive->id], $ids);
    }

    #[Test]
    public function sort_by_name_asc(): void
    {
        $b = Product::factory()->create(['name' => 'Бета']);
        $a = Product::factory()->create(['name' => 'Альфа']);
        $g = Product::factory()->create(['name' => 'Гамма']);

        $this->favorite($b);
        $this->favorite($a);
        $this->favorite($g);

        $ids = array_column($this->fetchFavorites('sort=name_asc'), 'id');

        $this->assertSame([$a->id, $b->id, $g->id], $ids);
    }

    #[Test]
    public function invalid_sort_falls_back_to_default(): void
    {
        $first = Product::factory()->create(['name' => 'P1']);
        $this->favorite($first)->update(['created_at' => now()->subDay()]);

        $second = Product::factory()->create(['name' => 'P2']);
        $this->favorite($second)->update(['created_at' => now()]);

        $ids = array_column($this->fetchFavorites('sort=garbage_value'), 'id');

        $this->assertSame([$second->id, $first->id], $ids);
    }

    #[Test]
    public function filter_in_stock_excludes_out_of_stock(): void
    {
        $inStock = Product::factory()->create(['name' => 'В наличии']);
        $this->setStock($inStock, $this->primaryWarehouse, 10);
        $this->favorite($inStock);

        $outOfStock = Product::factory()->create(['name' => 'Нет в наличии']);
        $this->favorite($outOfStock);

        $onlyPreorder = Product::factory()->create(['name' => 'Предзаказ']);
        $this->setStock($onlyPreorder, $this->preorderWarehouse, 5);
        $this->favorite($onlyPreorder);

        $ids = array_column($this->fetchFavorites('availability=in_stock'), 'id');

        $this->assertContains($inStock->id, $ids);
        $this->assertNotContains($outOfStock->id, $ids);
        $this->assertNotContains($onlyPreorder->id, $ids);
    }

    #[Test]
    public function filter_preorder_finds_only_preorder(): void
    {
        $inStock = Product::factory()->create(['name' => 'Главный']);
        $this->setStock($inStock, $this->primaryWarehouse, 10);
        $this->favorite($inStock);

        $preorder = Product::factory()->create(['name' => 'Предзаказ-товар']);
        $this->setStock($preorder, $this->preorderWarehouse, 5);
        $this->favorite($preorder);

        $ids = array_column($this->fetchFavorites('availability=preorder'), 'id');

        $this->assertContains($preorder->id, $ids);
        $this->assertNotContains($inStock->id, $ids);
    }

    #[Test]
    public function filter_out_of_stock_excludes_anything_with_stock(): void
    {
        $inStock = Product::factory()->create(['name' => 'Есть остатки']);
        $this->setStock($inStock, $this->primaryWarehouse, 10);
        $this->favorite($inStock);

        $outOfStock = Product::factory()->create(['name' => 'Без остатков']);
        $this->favorite($outOfStock);

        $ids = array_column($this->fetchFavorites('availability=out_of_stock'), 'id');

        $this->assertContains($outOfStock->id, $ids);
        $this->assertNotContains($inStock->id, $ids);
    }

    #[Test]
    public function filter_by_brand_ids(): void
    {
        $adidas = Brand::create(['name' => 'AdidasMulti', 'slug' => 'adidas-multi-'.Str::random(5)]);
        $nike = Brand::create(['name' => 'NikeMulti', 'slug' => 'nike-multi-'.Str::random(5)]);

        $a = Product::factory()->create(['brand_id' => $adidas->id]);
        $n = Product::factory()->create(['brand_id' => $nike->id]);
        $no = Product::factory()->create();

        $this->favorite($a);
        $this->favorite($n);
        $this->favorite($no);

        $ids = array_column($this->fetchFavorites('brand_ids[]='.$adidas->id), 'id');

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($n->id, $ids);
        $this->assertNotContains($no->id, $ids);
    }

    #[Test]
    public function filter_by_category_ids(): void
    {
        $shoes = Category::create(['name' => 'Обувь', 'slug' => 'shoes-fav-'.Str::random(5)]);
        $clothes = Category::create(['name' => 'Одежда', 'slug' => 'clothes-fav-'.Str::random(5)]);

        $s = Product::factory()->create(['category_id' => $shoes->id]);
        $c = Product::factory()->create(['category_id' => $clothes->id]);

        $this->favorite($s);
        $this->favorite($c);

        $ids = array_column($this->fetchFavorites('category_ids[]='.$shoes->id), 'id');

        $this->assertContains($s->id, $ids);
        $this->assertNotContains($c->id, $ids);
    }

    #[Test]
    public function facets_only_include_brands_present_in_user_favorites(): void
    {
        $usedBrand = Brand::create(['name' => 'Используемый', 'slug' => 'used-fav-'.Str::random(5)]);
        $unusedBrand = Brand::create(['name' => 'Не используемый', 'slug' => 'unused-fav-'.Str::random(5)]);

        $product = Product::factory()->create(['brand_id' => $usedBrand->id]);
        $this->favorite($product);

        $response = $this->actingAs($this->user)->get('/favorites');
        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $brands = collect($page['props']['facets']['brands']);

        $this->assertTrue($brands->contains('id', $usedBrand->id));
        $this->assertFalse($brands->contains('id', $unusedBrand->id));
    }
}
