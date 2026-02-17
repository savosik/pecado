<?php

namespace Tests\Feature;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Mock PriceService
        $priceServiceMock = $this->createMock(PriceServiceInterface::class);
        $priceServiceMock->method('getUserPrice')->willReturn(100.0);
        $priceServiceMock->method('getBasePrice')->willReturn(120.0);
        $priceServiceMock->method('getDiscountedPrice')->willReturn(100.0);
        $priceServiceMock->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $priceServiceMock);

        // Mock StockService
        $stockServiceMock = $this->createMock(StockServiceInterface::class);
        $stockServiceMock->method('getStock')->willReturn(['available' => 10, 'preorder' => 5]);
        $stockServiceMock->method('getAvailableStock')->willReturn(10);
        $stockServiceMock->method('getPreorderStock')->willReturn(5);
        $this->app->instance(StockServiceInterface::class, $stockServiceMock);
    }

    // ─── Auth ─────────────────────────────────────────────

    public function test_checkout_page_requires_auth(): void
    {
        $response = $this->get('/checkout');
        $response->assertRedirect(route('login'));
    }

    // ─── Empty Cart Redirect ──────────────────────────────

    public function test_checkout_with_empty_cart_redirects_to_cart(): void
    {
        Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->get('/checkout');

        $response->assertRedirect(route('cart.index'));
    }

    public function test_checkout_creates_cart_if_none_and_redirects(): void
    {
        // Нет корзины — создаётся пустая → редирект
        $response = $this->actingAs($this->user)->get('/checkout');

        $response->assertRedirect(route('cart.index'));

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);
    }

    // ─── Checkout Page Renders ────────────────────────────

    public function test_checkout_page_renders_with_items(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->get('/checkout');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/Checkout/Index')
            ->has('cart')
            ->has('cartDetails')
            ->where('cart.id', $cart->id)
        );
    }
}
