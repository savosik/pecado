<?php

namespace Tests\Feature\User;

use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CabinetCartListSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCarts(string $queryString = ''): array
    {
        $url = '/cabinet/carts'.($queryString !== '' ? '?'.$queryString : '');
        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['carts']['data'] ?? [];
    }

    private function makeCart(string $name, bool $isActive = false): Cart
    {
        return Cart::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'is_active' => $isActive,
        ]);
    }

    private function addItem(Cart $cart, Product $product, int $qty = 1, float $price = 100.0): CartItem
    {
        return CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => $price,
            'item_type' => 'instock',
            'warehouse_id' => null,
        ]);
    }

    #[Test]
    public function search_by_cart_name(): void
    {
        $matching = $this->makeCart('Закупка на май');
        $other = $this->makeCart('Прочее');

        $ids = array_column($this->fetchCarts('search='.urlencode('май')), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_product_name_in_composition(): void
    {
        $product = Product::factory()->create(['name' => 'Кроссовки Air Max']);
        $matching = $this->makeCart('Корзина-1');
        $this->addItem($matching, $product);

        $unrelated = $this->makeCart('Корзина-2');
        $this->addItem($unrelated, Product::factory()->create(['name' => 'Прочая обувь']));

        $rows = $this->fetchCarts('search='.urlencode('Air Max'));
        $ids = array_column($rows, 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);

        $row = collect($rows)->firstWhere('id', $matching->id);
        $this->assertSame('composition', $row['match_source']);
    }

    #[Test]
    public function search_by_brand_in_composition(): void
    {
        $brand = Brand::create(['name' => 'AdidasCarts', 'slug' => 'adidas-carts-'.Str::random(5)]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        $matching = $this->makeCart('Корзина с брендом');
        $this->addItem($matching, $product);

        $other = $this->makeCart('Прочая корзина');
        $this->addItem($other, Product::factory()->create());

        $ids = array_column($this->fetchCarts('search=AdidasCarts'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_exact_barcode_in_composition(): void
    {
        $product = Product::factory()->create();
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);

        $matching = $this->makeCart('С штрихкодом');
        $this->addItem($matching, $product);

        $other = $this->makeCart('Без штрихкода');
        $this->addItem($other, Product::factory()->create());

        $ids = array_column($this->fetchCarts('search=4607123456789'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function match_source_is_name_when_query_matches_cart_name(): void
    {
        $cart = $this->makeCart('Майская закупка');
        $product = Product::factory()->create();
        $this->addItem($cart, $product);

        $row = collect($this->fetchCarts('search=Майская'))->firstWhere('id', $cart->id);

        $this->assertSame('name', $row['match_source']);
    }

    #[Test]
    public function sort_by_name_asc(): void
    {
        $b = $this->makeCart('Бета');
        $a = $this->makeCart('Альфа');
        $g = $this->makeCart('Гамма');

        $ids = array_column($this->fetchCarts('sort_by=name&sort_order=asc'), 'id');

        $this->assertSame([$a->id, $b->id, $g->id], $ids);
    }

    #[Test]
    public function sort_by_total_amount_desc(): void
    {
        $cheap = $this->makeCart('Дешёвая');
        $this->addItem($cheap, Product::factory()->create(), qty: 1, price: 100);

        $expensive = $this->makeCart('Дорогая');
        $this->addItem($expensive, Product::factory()->create(), qty: 5, price: 500);

        $mid = $this->makeCart('Средняя');
        $this->addItem($mid, Product::factory()->create(), qty: 2, price: 300);

        $ids = array_column($this->fetchCarts('sort_by=total_amount&sort_order=desc'), 'id');

        $this->assertSame([$expensive->id, $mid->id, $cheap->id], $ids);
    }

    #[Test]
    public function filter_amount_from_excludes_cheaper_carts(): void
    {
        $cheap = $this->makeCart('Дешёвая');
        $this->addItem($cheap, Product::factory()->create(), qty: 1, price: 50);

        $rich = $this->makeCart('Богатая');
        $this->addItem($rich, Product::factory()->create(), qty: 10, price: 200);

        $ids = array_column($this->fetchCarts('amount_from=500'), 'id');

        $this->assertContains($rich->id, $ids);
        $this->assertNotContains($cheap->id, $ids);
    }

    #[Test]
    public function filter_amount_to_excludes_richer_carts(): void
    {
        $cheap = $this->makeCart('Дешёвая');
        $this->addItem($cheap, Product::factory()->create(), qty: 1, price: 50);

        $rich = $this->makeCart('Богатая');
        $this->addItem($rich, Product::factory()->create(), qty: 10, price: 200);

        $ids = array_column($this->fetchCarts('amount_to=500'), 'id');

        $this->assertContains($cheap->id, $ids);
        $this->assertNotContains($rich->id, $ids);
    }

    #[Test]
    public function filter_only_empty(): void
    {
        $empty = $this->makeCart('Пустая');

        $filled = $this->makeCart('Заполненная');
        $this->addItem($filled, Product::factory()->create());

        $ids = array_column($this->fetchCarts('only_empty=1'), 'id');

        $this->assertContains($empty->id, $ids);
        $this->assertNotContains($filled->id, $ids);
    }

    #[Test]
    public function filter_only_active(): void
    {
        $active = $this->makeCart('Активная', isActive: true);
        $inactive = $this->makeCart('Не активная', isActive: false);

        $ids = array_column($this->fetchCarts('only_active=1'), 'id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    #[Test]
    public function filter_items_count_from(): void
    {
        $small = $this->makeCart('Маленькая');
        $this->addItem($small, Product::factory()->create());

        $big = $this->makeCart('Большая');
        $this->addItem($big, Product::factory()->create());
        $this->addItem($big, Product::factory()->create());
        $this->addItem($big, Product::factory()->create());

        $ids = array_column($this->fetchCarts('items_count_from=3'), 'id');

        $this->assertContains($big->id, $ids);
        $this->assertNotContains($small->id, $ids);
    }

    #[Test]
    public function deduplication_one_cart_one_row(): void
    {
        $product = Product::factory()->create(['name' => 'Уникальный товар Air']);
        $cart = $this->makeCart('Корзина с дубликатом');
        $this->addItem($cart, $product, qty: 1);
        // Тот же товар, но другой item_type — две записи в cart_items, но одна корзина.
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 50,
            'item_type' => 'preorder',
            'warehouse_id' => null,
        ]);

        $rows = $this->fetchCarts('search='.urlencode('Air'));
        $matches = array_filter($rows, fn ($r) => $r['id'] === $cart->id);

        $this->assertCount(1, $matches);
    }

    #[Test]
    public function scope_excludes_other_users_carts(): void
    {
        $mine = $this->makeCart('Моя');
        $other = User::factory()->create();
        $foreign = Cart::create(['user_id' => $other->id, 'name' => 'Чужая']);

        $ids = array_column($this->fetchCarts(), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function invalid_sort_falls_back_to_default(): void
    {
        $first = $this->makeCart('Первая');
        DB::table('carts')->where('id', $first->id)->update(['updated_at' => now()->subDay()]);

        $second = $this->makeCart('Вторая');
        DB::table('carts')->where('id', $second->id)->update(['updated_at' => now()]);

        $ids = array_column($this->fetchCarts('sort_by=garbage'), 'id');

        $this->assertSame([$second->id, $first->id], $ids);
    }
}
