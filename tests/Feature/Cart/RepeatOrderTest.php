<?php

namespace Tests\Feature\Cart;

use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepeatOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $priceService = $this->createMock(PriceServiceInterface::class);
        $priceService->method('getUserPrice')->willReturn(100.0);
        $priceService->method('getBasePrice')->willReturn(120.0);
        $priceService->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $priceService->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $priceService);

        $stockService = $this->createMock(StockServiceInterface::class);
        $stockService->method('getStock')->willReturn(['available' => 100, 'preorder' => 50]);
        $stockService->method('getAvailableStock')->willReturn(100);
        $stockService->method('getPreorderStock')->willReturn(50);
        $this->app->instance(StockServiceInterface::class, $stockService);
    }

    private function makeOrder(array $items): Order
    {
        $order = Order::factory()->create(['user_id' => $this->user->id, 'company_id' => null]);
        foreach ($items as $item) {
            OrderItem::factory()->create(array_merge(['order_id' => $order->id], $item));
        }

        return $order;
    }

    public function test_repeat_into_empty_cart_adds_items(): void
    {
        $p1 = Product::factory()->create();
        $p2 = Product::factory()->create();
        $order = $this->makeOrder([
            ['product_id' => $p1->id, 'quantity' => 2],
            ['product_id' => $p2->id, 'quantity' => 3],
        ]);

        $response = $this->actingAs($this->user)->postJson("/cabinet/orders/{$order->id}/repeat", [
            'mode' => 'merge',
        ]);

        $response->assertOk()
            ->assertJsonPath('added_count', 2)
            ->assertJsonPath('skipped_count', 0);

        $cart = $this->user->carts()->where('is_active', true)->first();
        $this->assertSame(2, (int) $cart->items()->where('product_id', $p1->id)->sum('quantity'));
        $this->assertSame(3, (int) $cart->items()->where('product_id', $p2->id)->sum('quantity'));
    }

    public function test_repeat_merge_is_additive_to_existing_cart(): void
    {
        $product = Product::factory()->create();
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'quantity' => 3],
        ]);

        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->postJson("/cabinet/orders/{$order->id}/repeat", [
            'mode' => 'merge',
        ]);

        $response->assertOk()->assertJsonPath('added_count', 1);
        $this->assertSame(5, (int) $cart->items()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_repeat_replace_clears_cart_first(): void
    {
        $existing = Product::factory()->create();
        $ordered = Product::factory()->create();
        $order = $this->makeOrder([
            ['product_id' => $ordered->id, 'quantity' => 4],
        ]);

        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $existing->id,
            'quantity' => 7,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->postJson("/cabinet/orders/{$order->id}/repeat", [
            'mode' => 'replace',
        ]);

        $response->assertOk()->assertJsonPath('mode', 'replace');
        $this->assertSame(0, (int) $cart->items()->where('product_id', $existing->id)->sum('quantity'));
        $this->assertSame(4, (int) $cart->items()->where('product_id', $ordered->id)->sum('quantity'));
    }

    public function test_items_without_product_are_skipped(): void
    {
        $product = Product::factory()->create();
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => null, 'name' => 'Снятый с каталога товар', 'quantity' => 5],
        ]);

        $response = $this->actingAs($this->user)->postJson("/cabinet/orders/{$order->id}/repeat", [
            'mode' => 'merge',
        ]);

        $response->assertOk()
            ->assertJsonPath('added_count', 1)
            ->assertJsonPath('skipped_count', 1);
    }

    public function test_repeat_with_no_repeatable_items_returns_422(): void
    {
        $order = $this->makeOrder([
            ['product_id' => null, 'name' => 'Только снятый товар', 'quantity' => 1],
        ]);

        $response = $this->actingAs($this->user)->postJson("/cabinet/orders/{$order->id}/repeat", [
            'mode' => 'merge',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('added_count', 0)
            ->assertJsonPath('status', 'warning');
    }

    public function test_cannot_repeat_another_users_order(): void
    {
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->create(['user_id' => $other->id, 'company_id' => null]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1]);

        $response = $this->actingAs($this->user)->postJson("/cabinet/orders/{$order->id}/repeat", [
            'mode' => 'merge',
        ]);

        $response->assertForbidden();
    }
}
