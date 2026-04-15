<?php

namespace Tests\Feature;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Order;
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
        $priceServiceMock->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
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
            ->has('instockItems')
            ->has('preorderItems')
            ->has('instockTotals')
            ->has('preorderTotals')
            ->has('grandTotal')
            ->has('companies')
            ->has('addresses')
            ->where('cart.id', $cart->id)
        );
    }

    // ─── Store ────────────────────────────────────────────

    public function test_checkout_store_validates_company(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
        ]);

        $response->assertSessionHasErrors('company_id');
    }

    public function test_checkout_store_creates_order(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);

        $company = Company::factory()->create(['user_id' => $this->user->id]);

        // Mock CheckoutService, чтобы не зависеть от реальной логики
        $order = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Ленина, д. 5',
            'status' => \App\Enums\OrderStatus::PENDING,
            'total_amount' => 200.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ]);
        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->expects($this->once())
            ->method('checkout')
            ->willReturn(collect([$order]));
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Ленина, д. 5',
            'comment' => 'Тестовый комментарий',
        ]);

        $response->assertRedirect(route('cabinet.orders.show', $order));
    }

    public function test_checkout_store_with_new_address(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'item_type' => 'instock',
        ]);

        $company = Company::factory()->create(['user_id' => $this->user->id]);

        // Mock CheckoutService
        $order = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_address' => 'Москва, ул. Тестовая, д. 1',
            'status' => \App\Enums\OrderStatus::PENDING,
            'total_amount' => 100.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ]);
        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->expects($this->once())
            ->method('checkout')
            ->willReturn(collect([$order]));
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_address' => 'Москва, ул. Тестовая, д. 1',
            'comment' => '',
        ]);

        $response->assertRedirect(route('cabinet.orders.show', $order));
    }

    public function test_checkout_store_with_two_order_types_redirects_to_orders_index(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'item_type' => 'instock',
        ]);

        $company = Company::factory()->create(['user_id' => $this->user->id]);

        // Мок возвращает два заказа (instock + preorder)
        $order1 = Order::create([
            'uuid'             => \Illuminate\Support\Str::uuid(),
            'user_id'          => $this->user->id,
            'company_id'       => $company->id,
            'delivery_address' => 'г. Москва, ул. Мира, д. 10',
            'status'           => \App\Enums\OrderStatus::PENDING,
            'total_amount'     => 100.00,
            'exchange_rate'    => 1,
            'rate_coefficient' => 1,
            'currency_code'    => 'RUB',
            'type'             => \App\Enums\OrderType::ORDER,
        ]);
        $order2 = Order::create([
            'uuid'             => \Illuminate\Support\Str::uuid(),
            'user_id'          => $this->user->id,
            'company_id'       => $company->id,
            'delivery_address' => 'г. Москва, ул. Мира, д. 10',
            'status'           => \App\Enums\OrderStatus::PENDING,
            'total_amount'     => 200.00,
            'exchange_rate'    => 1,
            'rate_coefficient' => 1,
            'currency_code'    => 'RUB',
            'type'             => \App\Enums\OrderType::PREORDER,
        ]);

        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->expects($this->once())
            ->method('checkout')
            ->willReturn(collect([$order1, $order2]));
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id'       => $company->id,
            'delivery_address' => 'г. Москва, ул. Мира, д. 10',
        ]);

        // Два заказа → редирект на список заказов, а не на один заказ
        $response->assertRedirect(route('cabinet.orders.index'));
        $response->assertSessionHas('success');
    }
}
