<?php

namespace Tests\Feature\User;

use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CabinetCartProductSearchTest extends TestCase
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
    private function search(string $query): array
    {
        $url = '/cabinet/carts/search-products?query='.urlencode($query);
        $response = $this->actingAs($this->user)->getJson($url);
        $response->assertOk();

        return $response->json();
    }

    #[Test]
    public function returns_empty_for_short_query(): void
    {
        Product::factory()->create(['name' => 'Кроссовки']);

        $this->assertSame([], $this->search('к'));
        $this->assertSame([], $this->search(''));
    }

    #[Test]
    public function search_by_name(): void
    {
        $matching = Product::factory()->create(['name' => 'Кроссовки Air Max']);
        $other = Product::factory()->create(['name' => 'Прочая обувь']);

        $ids = array_column($this->search('Air Max'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_sku(): void
    {
        $matching = Product::factory()->create(['sku' => 'AM90-001']);
        $other = Product::factory()->create(['sku' => 'OTHER-X']);

        $ids = array_column($this->search('AM90-001'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_code(): void
    {
        $matching = Product::factory()->create(['code' => 'CODE-PRD-77']);
        $other = Product::factory()->create(['code' => 'OTHER-CODE']);

        $ids = array_column($this->search('PRD-77'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_brand_name(): void
    {
        $brand = Brand::create(['name' => 'AdidasCart', 'slug' => 'adidas-cart']);
        $matching = Product::factory()->create(['brand_id' => $brand->id]);
        $other = Product::factory()->create();

        $ids = array_column($this->search('AdidasCart'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_partial_barcode(): void
    {
        $product = Product::factory()->create();
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);
        $other = Product::factory()->create();

        $ids = array_column($this->search('123456'), 'id');

        $this->assertContains($product->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function exact_barcode_match_is_first_with_match_source(): void
    {
        $exactProduct = Product::factory()->create(['name' => 'Точный матч']);
        ProductBarcode::create(['product_id' => $exactProduct->id, 'barcode' => '4607123456789']);

        // Другой товар, чей SKU тоже содержит часть штрихкода — попадёт через LIKE.
        $otherProduct = Product::factory()->create(['name' => 'Прочее', 'sku' => '4607123']);

        $rows = $this->search('4607123456789');

        $this->assertNotEmpty($rows);
        $this->assertSame($exactProduct->id, $rows[0]['id']);
        $this->assertSame('barcode_exact', $rows[0]['match_source']);

        $other = collect($rows)->firstWhere('id', $otherProduct->id);
        if ($other !== null) {
            $this->assertNull($other['match_source']);
        }
    }

    #[Test]
    public function match_source_is_null_for_text_query(): void
    {
        $product = Product::factory()->create(['name' => 'Просто товар']);

        $rows = $this->search('Просто');
        $row = collect($rows)->firstWhere('id', $product->id);

        $this->assertNull($row['match_source']);
    }

    #[Test]
    public function purchased_count_counts_only_user_orders_in_relevant_status(): void
    {
        $product = Product::factory()->create(['name' => 'Уник товар покупок']);

        $confirmed = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);
        OrderItem::factory()->create([
            'order_id' => $confirmed->id,
            'product_id' => $product->id,
        ]);

        $closed = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::CLOSED,
        ]);
        OrderItem::factory()->create([
            'order_id' => $closed->id,
            'product_id' => $product->id,
        ]);

        // Pending — не считается.
        $pending = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);
        OrderItem::factory()->create([
            'order_id' => $pending->id,
            'product_id' => $product->id,
        ]);

        // Заказ другого пользователя — не считается.
        $other = User::factory()->create();
        $foreign = Order::factory()->create([
            'user_id' => $other->id,
            'status' => OrderStatus::READY_FOR_PROVISION,
        ]);
        OrderItem::factory()->create([
            'order_id' => $foreign->id,
            'product_id' => $product->id,
        ]);

        $row = collect($this->search('Уник товар покупок'))->firstWhere('id', $product->id);

        $this->assertSame(2, $row['purchased_count']);
    }

    #[Test]
    public function purchased_count_zero_when_no_orders(): void
    {
        $product = Product::factory()->create(['name' => 'Невостребованный']);

        $row = collect($this->search('Невостребованный'))->firstWhere('id', $product->id);

        $this->assertSame(0, $row['purchased_count']);
    }

    #[Test]
    public function in_favorites_true_when_in_user_favorites(): void
    {
        $product = Product::factory()->create(['name' => 'Любимый товар']);
        Favorite::create(['user_id' => $this->user->id, 'product_id' => $product->id]);

        $row = collect($this->search('Любимый'))->firstWhere('id', $product->id);

        $this->assertTrue($row['in_favorites']);
    }

    #[Test]
    public function in_favorites_false_for_other_users_favorite(): void
    {
        $product = Product::factory()->create(['name' => 'Чужой избранный']);
        $other = User::factory()->create();
        Favorite::create(['user_id' => $other->id, 'product_id' => $product->id]);

        $row = collect($this->search('Чужой избранный'))->firstWhere('id', $product->id);

        $this->assertFalse($row['in_favorites']);
    }

    #[Test]
    public function exact_sku_match_ranks_above_partial_text_match(): void
    {
        // Точное совпадение по sku
        $exact = Product::factory()->create([
            'name' => 'Какой-то товар',
            'sku' => 'AIR-90',
        ]);
        // Лишь по name содержит "AIR-90" в составе
        $partial = Product::factory()->create([
            'name' => 'Описание AIR-90 в строке',
            'sku' => 'OTHER',
        ]);

        $rows = $this->search('AIR-90');
        $ids = array_column($rows, 'id');

        $exactIdx = array_search($exact->id, $ids, true);
        $partialIdx = array_search($partial->id, $ids, true);

        $this->assertNotFalse($exactIdx, 'Точный матч должен быть в выдаче');
        $this->assertNotFalse($partialIdx, 'Частичный матч должен быть в выдаче');
        $this->assertLessThan($partialIdx, $exactIdx, 'Точный sku-матч должен быть выше частичного');
    }

    #[Test]
    public function limit_is_15_results(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Product::factory()->create(['name' => 'BULK-CART-'.$i]);
        }

        $rows = $this->search('BULK-CART');

        $this->assertCount(15, $rows);
    }

    #[Test]
    public function exact_barcode_does_not_appear_twice(): void
    {
        $product = Product::factory()->create(['name' => 'Уник barcode']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);

        $rows = $this->search('4607123456789');
        $count = collect($rows)->where('id', $product->id)->count();

        $this->assertSame(1, $count);
    }
}
