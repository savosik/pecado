<?php

namespace Tests\Unit\Services\Cart;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CartServiceTest extends TestCase
{
    private CartService $service;
    private PriceServiceInterface $priceService;
    private StockServiceInterface $stockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priceService = $this->createMock(PriceServiceInterface::class);
        $this->stockService = $this->createMock(StockServiceInterface::class);
        $this->service = new CartService($this->priceService, $this->stockService);
    }

    #[Test]
    public function calculates_total_price_in_user_currency(): void
    {
        $user = $this->createMock(User::class);
        $product = $this->createMock(Product::class);

        $item = $this->createMock(CartItem::class);
        $item->method('__get')->willReturnCallback(fn($key) => match($key) {
            'product' => $product,
            'quantity' => 3,
            default => null
        });

        $items = new Collection([$item]);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'items' ? $items : null);

        $this->priceService->method('getUserPrice')->with($product, $user)->willReturn(100.0);
        $this->stockService->method('getStock')->with($product, $user)->willReturn(['available' => 10, 'preorder' => 5]);

        $result = $this->service->getCartSummary($cart, $user);

        $this->assertEquals(300.0, $result['total_price']);
        $this->assertEquals(3, $result['items_count']);
    }

    #[Test]
    public function calculates_available_items_count(): void
    {
        $user = $this->createMock(User::class);
        $product = $this->createMock(Product::class);

        $item = $this->createMock(CartItem::class);
        $item->method('__get')->willReturnCallback(fn($key) => match($key) {
            'product' => $product,
            'quantity' => 5,
            default => null
        });

        $items = new Collection([$item]);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'items' ? $items : null);

        $this->priceService->method('getUserPrice')->willReturn(100.0);
        $this->stockService->method('getStock')->with($product, $user)->willReturn(['available' => 10, 'preorder' => 5]);

        $result = $this->service->getCartSummary($cart, $user);

        $this->assertEquals(5, $result['available_count']);
        $this->assertEquals(0, $result['preorder_count']);
    }

    #[Test]
    public function calculates_preorder_items_count(): void
    {
        $user = $this->createMock(User::class);
        $product = $this->createMock(Product::class);

        $item = $this->createMock(CartItem::class);
        $item->method('__get')->willReturnCallback(fn($key) => match($key) {
            'product' => $product,
            'quantity' => 8,
            default => null
        });

        $items = new Collection([$item]);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'items' ? $items : null);

        $this->priceService->method('getUserPrice')->willReturn(100.0);
        // Only 3 available, so 5 should be preorder (but only 2 preorder stock available)
        $this->stockService->method('getStock')->with($product, $user)->willReturn(['available' => 3, 'preorder' => 2]);

        $result = $this->service->getCartSummary($cart, $user);

        $this->assertEquals(3, $result['available_count']);
        $this->assertEquals(2, $result['preorder_count']);
    }

    #[Test]
    public function handles_empty_cart(): void
    {
        $user = $this->createMock(User::class);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'items' ? new Collection([]) : null);

        $result = $this->service->getCartSummary($cart, $user);

        $this->assertEquals(0.0, $result['total_price']);
        $this->assertEquals(0, $result['items_count']);
        $this->assertEquals(0, $result['available_count']);
        $this->assertEquals(0, $result['preorder_count']);
    }

    #[Test]
    public function get_carts_summary_returns_summary_for_each_cart(): void
    {
        $user = $this->createMock(User::class);
        
        $cart1 = $this->createMock(Cart::class);
        $cart1->method('loadMissing')->willReturnSelf();
        $cart1->method('__get')->willReturnCallback(fn($key) => match($key) {
            'id' => 1,
            'items' => new Collection([]),
            default => null
        });

        $cart2 = $this->createMock(Cart::class);
        $cart2->method('loadMissing')->willReturnSelf();
        $cart2->method('__get')->willReturnCallback(fn($key) => match($key) {
            'id' => 2,
            'items' => new Collection([]),
            default => null
        });

        $carts = new Collection([$cart1, $cart2]);

        $result = $this->service->getCartsSummary($carts, $user);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
    }
}
