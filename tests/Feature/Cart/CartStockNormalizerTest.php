<?php

namespace Tests\Feature\Cart;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartStockNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Переразбивка корзины по остаткам своего вида (топик №17): строка «в наличии»
 * сверяется с основным складом, «предзаказ» — со складом предзаказа, а товар
 * переразбивается как при вводе количества (CartService::setProductQuantity).
 */
class CartStockNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{available: int, preorder: int}> */
    private array $stock = [];

    private User $user;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $stocks = $this->createMock(StockServiceInterface::class);
        $stocks->method('getStock')->willReturnCallback(fn (Product $p) => $this->stock[$p->id]);
        $this->app->instance(StockServiceInterface::class, $stocks);

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
    }

    private function product(int $available, int $preorder): Product
    {
        $product = Product::factory()->create();
        $this->stock[$product->id] = ['available' => $available, 'preorder' => $preorder];

        return $product;
    }

    private function line(Product $product, int $quantity, string $type, float $price = 250.0): CartItem
    {
        return CartItem::factory()->create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'item_type' => $type,
        ]);
    }

    /** @return array<string, int> */
    private function split(Product $product): array
    {
        return $this->cart->items()->where('product_id', $product->id)->get()
            ->mapWithKeys(fn (CartItem $i) => [$i->item_type => (int) $i->quantity])
            ->sortKeys()->all();
    }

    private function normalize(): array
    {
        return app(CartStockNormalizer::class)->normalize($this->user, $this->cart);
    }

    #[Test]
    #[TestDox('Наличие 0, предзаказ 37: строка «в наличии» целиком уходит в предзаказ с той же ценой')]
    public function instock_line_moves_to_preorder(): void
    {
        $product = $this->product(0, 37);
        $this->line($product, 1, 'instock', 777.0);

        $result = $this->normalize();

        $this->assertSame(['adjusted' => 1, 'removed' => 0, 'moved_to_preorder' => 1, 'remaining_lines' => 1], $result);
        $this->assertSame(['preorder' => 1], $this->split($product));
        $this->assertEquals(777.0, (float) $this->cart->items()->value('price'));
    }

    #[Test]
    #[TestDox('Нехватка сверх обоих складов: в наличии сколько есть, в предзаказ — сколько есть, остальное снимается')]
    public function total_is_clamped_to_both_warehouses(): void
    {
        $product = $this->product(2, 3);
        $this->line($product, 4, 'instock');
        $this->line($product, 4, 'preorder');

        $result = $this->normalize();

        $this->assertSame(['instock' => 2, 'preorder' => 3], $this->split($product));
        $this->assertSame(1, $result['adjusted']);
        $this->assertSame(0, $result['moved_to_preorder'], 'предзаказ уменьшился, а не вырос');
    }

    #[Test]
    #[TestDox('Предзаказ сверх своего склада при остатке на основном переходит в наличие')]
    public function preorder_excess_moves_to_instock(): void
    {
        $product = $this->product(10, 1);
        $this->line($product, 3, 'preorder');

        $this->normalize();

        $this->assertSame(['instock' => 3], $this->split($product));
    }

    #[Test]
    #[TestDox('Без остатка нигде — строки товара убираются, счётчик removed')]
    public function product_without_any_stock_is_removed(): void
    {
        $product = $this->product(0, 0);
        $this->line($product, 2, 'instock');
        $this->line($product, 1, 'preorder');

        $result = $this->normalize();

        $this->assertSame([], $this->split($product));
        $this->assertSame(['adjusted' => 0, 'removed' => 1, 'moved_to_preorder' => 0, 'remaining_lines' => 0], $result);
    }

    #[Test]
    #[TestDox('Товар в пределах своих складов не трогается, даже если разбивка отличается от «по максимуму в наличии»')]
    public function product_within_own_stock_is_untouched(): void
    {
        $product = $this->product(5, 5);
        $instock = $this->line($product, 1, 'instock');
        $preorder = $this->line($product, 2, 'preorder');

        $result = $this->normalize();

        $this->assertSame(0, $result['adjusted'] + $result['removed']);
        $this->assertSame(1, (int) $instock->fresh()->quantity);
        $this->assertSame(2, (int) $preorder->fresh()->quantity);
    }
}
