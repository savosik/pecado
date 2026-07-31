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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CartServiceTest extends TestCase
{
    private CartService $service;

    private PriceServiceInterface $priceService;

    private StockServiceInterface $stockService;

    private \App\Contracts\Defect\DefectStockServiceInterface $defectStockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priceService = $this->createMock(PriceServiceInterface::class);
        $this->stockService = $this->createMock(StockServiceInterface::class);
        $this->defectStockService = $this->createMock(\App\Contracts\Defect\DefectStockServiceInterface::class);
        $this->service = new CartService(
            $this->priceService,
            $this->stockService,
            $this->defectStockService,
            // Промо-строки в этих тестах не участвуют — они про агрегаты корзины
            $this->createMock(\App\Services\Promotion\CartPromoLines::class),
        );
    }

    #[Test]
    public function calculates_total_price_in_user_currency(): void
    {
        $user = $this->createMock(User::class);
        $product = $this->createMock(Product::class);

        $item = $this->createMock(CartItem::class);
        $item->method('__get')->willReturnCallback(fn ($key) => match ($key) {
            'product' => $product,
            'quantity' => 3,
            'item_type' => 'instock',
            default => null
        });
        $item->method('isInstock')->willReturn(true);

        $items = new Collection([$item]);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn ($key) => $key === 'items' ? $items : null);

        $this->priceService->method('getUserPrice')->with($product, $user)->willReturn(100.0);

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
        $item->method('__get')->willReturnCallback(fn ($key) => match ($key) {
            'product' => $product,
            'quantity' => 5,
            'item_type' => 'instock',
            default => null
        });
        $item->method('isInstock')->willReturn(true);

        $items = new Collection([$item]);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn ($key) => $key === 'items' ? $items : null);

        $this->priceService->method('getUserPrice')->willReturn(100.0);

        $result = $this->service->getCartSummary($cart, $user);

        $this->assertEquals(5, $result['available_count']);
        $this->assertEquals(0, $result['preorder_count']);
    }

    #[Test]
    public function calculates_preorder_items_count(): void
    {
        $user = $this->createMock(User::class);
        $product = $this->createMock(Product::class);

        $instockItem = $this->createMock(CartItem::class);
        $instockItem->method('__get')->willReturnCallback(fn ($key) => match ($key) {
            'product' => $product,
            'quantity' => 3,
            'item_type' => 'instock',
            default => null
        });
        $instockItem->method('isInstock')->willReturn(true);
        $instockItem->method('isPreorder')->willReturn(false);
        $instockItem->method('isDefect')->willReturn(false);

        $preorderItem = $this->createMock(CartItem::class);
        $preorderItem->method('__get')->willReturnCallback(fn ($key) => match ($key) {
            'product' => $product,
            'quantity' => 5,
            'item_type' => 'preorder',
            default => null
        });
        $preorderItem->method('isInstock')->willReturn(false);
        $preorderItem->method('isPreorder')->willReturn(true);
        $preorderItem->method('isDefect')->willReturn(false);

        $items = new Collection([$instockItem, $preorderItem]);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn ($key) => $key === 'items' ? $items : null);

        $this->priceService->method('getUserPrice')->willReturn(100.0);

        $result = $this->service->getCartSummary($cart, $user);

        $this->assertEquals(3, $result['available_count']);
        $this->assertEquals(5, $result['preorder_count']);
    }

    #[Test]
    public function handles_empty_cart(): void
    {
        $user = $this->createMock(User::class);

        $cart = $this->createMock(Cart::class);
        $cart->method('loadMissing')->willReturnSelf();
        $cart->method('__get')->willReturnCallback(fn ($key) => $key === 'items' ? new Collection([]) : null);

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
        $cart1->method('__get')->willReturnCallback(fn ($key) => match ($key) {
            'id' => 1,
            'items' => new Collection([]),
            default => null
        });

        $cart2 = $this->createMock(Cart::class);
        $cart2->method('loadMissing')->willReturnSelf();
        $cart2->method('__get')->willReturnCallback(fn ($key) => match ($key) {
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
