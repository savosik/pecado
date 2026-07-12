<?php

namespace Tests\Feature;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
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
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
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
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
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

    public function test_checkout_store_flashes_stock_conflicts_on_insufficient_stock(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 5,
            'item_type' => 'instock',
        ]);

        $company = Company::factory()->create(['user_id' => $this->user->id]);

        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->method('checkout')->willThrowException(
            new \App\Exceptions\InsufficientStockException(
                'Insufficient stock',
                [
                    [
                        'cart_item_id' => 42,
                        'product_id' => 7,
                        'product' => 'Тестовый товар',
                        'name' => 'Тестовый товар',
                        'sku' => 'TST-1',
                        'item_type' => 'instock',
                        'requested' => 5,
                        'available' => 2,
                    ],
                ],
            )
        );
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Ленина, д. 1',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('stock');
        $response->assertSessionHas('stock_conflicts', function ($items) {
            return is_array($items)
                && count($items) === 1
                && $items[0]['cart_item_id'] === 42
                && $items[0]['requested'] === 5
                && $items[0]['available'] === 2;
        });
    }

    public function test_normalize_stock_reduces_quantity_to_available(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);
        $product = Product::factory()->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'item_type' => 'instock',
        ]);

        // Доступно меньше, чем в корзине
        $stockMock = $this->createMock(StockServiceInterface::class);
        $stockMock->method('getStock')->willReturn(['available' => 3, 'preorder' => 0]);
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($this->user)->post('/checkout/normalize-stock');

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHas('success');
        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    public function test_normalize_stock_removes_items_with_zero_stock(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);
        $product = Product::factory()->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'item_type' => 'instock',
        ]);

        $stockMock = $this->createMock(StockServiceInterface::class);
        $stockMock->method('getStock')->willReturn(['available' => 0, 'preorder' => 0]);
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($this->user)->post('/checkout/normalize-stock');

        // Корзина опустеет — редирект на /cart
        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('warning');
        $this->assertNull(CartItem::find($item->id));
    }

    public function test_normalize_stock_is_noop_when_cart_already_matches(): void
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

        // setUp уже мокает stock = available 10 / preorder 5 — корзине места хватает
        $response = $this->actingAs($this->user)->post('/checkout/normalize-stock');

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHas('info');
    }

    public function test_checkout_index_exposes_stock_status_for_items(): void
    {
        $cart = Cart::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);

        $okProduct = Product::factory()->create();
        $partialProduct = Product::factory()->create();
        $unavailableProduct = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $okProduct->id,
            'quantity' => 1,
            'item_type' => 'instock',
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $partialProduct->id,
            'quantity' => 99,
            'item_type' => 'instock',
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $unavailableProduct->id,
            'quantity' => 1,
            'item_type' => 'instock',
        ]);

        // Подмешиваем разный сток на каждый товар
        $stockMock = $this->createMock(StockServiceInterface::class);
        $stockMock->method('getStock')->willReturnCallback(
            fn ($product) => match ($product->id) {
                $okProduct->id => ['available' => 50, 'preorder' => 0],
                $partialProduct->id => ['available' => 5, 'preorder' => 0],
                $unavailableProduct->id => ['available' => 0, 'preorder' => 0],
                default => ['available' => 0, 'preorder' => 0],
            }
        );
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($this->user)->get('/checkout');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/Checkout/Index')
            ->has('instockItems', 3)
            ->where('instockItems.0.stock_status', 'ok')
            ->where('instockItems.1.stock_status', 'partial')
            ->where('instockItems.2.stock_status', 'unavailable')
        );
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
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Мира, д. 10',
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
            'total_amount' => 100.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ]);
        $order2 = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Мира, д. 10',
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
            'total_amount' => 200.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::PREORDER,
        ]);

        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->expects($this->once())
            ->method('checkout')
            ->willReturn(collect([$order1, $order2]));
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Мира, д. 10',
        ]);

        // Два заказа → редирект на список заказов, а не на один заказ
        $response->assertRedirect(route('cabinet.orders.index'));
        $response->assertSessionHas('success');
    }

    // ─── Способ доставки (v15.3) ─────────────────────────

    public function test_checkout_store_pickup_without_address_succeeds(): void
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

        $order = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_address' => null,
            'delivery_method' => \App\Enums\DeliveryMethod::PICKUP,
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
            'total_amount' => 100.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        $capturedArgs = null;
        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->expects($this->once())
            ->method('checkout')
            ->willReturnCallback(function (...$args) use (&$capturedArgs, $order) {
                $capturedArgs = $args;

                return collect([$order]);
            });
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'pickup',
            // Адрес не передаём — при самовывозе не обязателен
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('cabinet.orders.show', $order));

        // Сервис получил null-адрес и PICKUP
        $this->assertNull($capturedArgs[2]);
        $this->assertSame(\App\Enums\DeliveryMethod::PICKUP, $capturedArgs[6]);
    }

    public function test_checkout_store_pickup_ignores_passed_address(): void
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

        $order = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_method' => \App\Enums\DeliveryMethod::PICKUP,
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
            'total_amount' => 100.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        $capturedArgs = null;
        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->method('checkout')
            ->willReturnCallback(function (...$args) use (&$capturedArgs, $order) {
                $capturedArgs = $args;

                return collect([$order]);
            });
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'pickup',
            'delivery_address' => 'г. Москва, ул. Лишняя, д. 1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        // exclude_if отбрасывает адрес при самовывозе — сервис получает null
        $this->assertNull($capturedArgs[2]);
    }

    public function test_checkout_store_delivery_requires_address(): void
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

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'delivery',
        ]);

        $response->assertSessionHasErrors('delivery_address');
    }

    public function test_checkout_store_rejects_invalid_delivery_method(): void
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

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'courier',
            'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
        ]);

        $response->assertSessionHasErrors('delivery_method');
    }

    // ─── Сохранение адреса и запоминание способа доставки ──

    /**
     * @return array{Order, \App\Models\Company}
     */
    private function mockSuccessfulCheckout(): array
    {
        $company = Company::factory()->create(['user_id' => $this->user->id]);

        $order = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'delivery_address' => 'Москва, ул. Новая, д. 7',
            'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
            'total_amount' => 100.00,
            'exchange_rate' => 1,
            'rate_coefficient' => 1,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        $checkoutMock = $this->createMock(CheckoutServiceInterface::class);
        $checkoutMock->method('checkout')->willReturn(collect([$order]));
        $this->app->instance(CheckoutServiceInterface::class, $checkoutMock);

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

        return [$order, $company];
    }

    public function test_checkout_store_saves_new_address_when_requested(): void
    {
        [$order, $company] = $this->mockSuccessfulCheckout();

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'Москва, ул. Новая, д. 7',
            'save_address' => true,
            'address_name' => 'Офис',
        ]);

        $response->assertRedirect(route('cabinet.orders.show', $order));
        $this->assertDatabaseHas('delivery_addresses', [
            'user_id' => $this->user->id,
            'name' => 'Офис',
            'address' => 'Москва, ул. Новая, д. 7',
            'is_default' => false,
        ]);
    }

    public function test_checkout_store_does_not_save_address_without_flag(): void
    {
        [, $company] = $this->mockSuccessfulCheckout();

        $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'Москва, ул. Новая, д. 7',
        ]);

        $this->assertDatabaseCount('delivery_addresses', 0);
    }

    public function test_checkout_store_saves_address_as_default_and_resets_others(): void
    {
        [, $company] = $this->mockSuccessfulCheckout();

        $old = \App\Models\DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => true,
        ]);

        $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'Москва, ул. Новая, д. 7',
            'save_address' => true,
            'address_name' => 'Новый',
            'address_make_default' => true,
        ]);

        $this->assertDatabaseHas('delivery_addresses', [
            'user_id' => $this->user->id,
            'address' => 'Москва, ул. Новая, д. 7',
            'is_default' => true,
        ]);
        $this->assertFalse($old->fresh()->is_default);
    }

    public function test_checkout_store_does_not_duplicate_existing_address(): void
    {
        [, $company] = $this->mockSuccessfulCheckout();

        \App\Models\DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'address' => 'Москва, ул. Новая, д. 7',
        ]);

        $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'Москва, ул. Новая, д. 7',
            'save_address' => true,
        ]);

        $this->assertDatabaseCount('delivery_addresses', 1);
    }

    public function test_checkout_store_remembers_delivery_method(): void
    {
        [, $company] = $this->mockSuccessfulCheckout();

        $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $company->id,
            'delivery_method' => 'pickup',
        ]);

        $this->assertSame(
            \App\Enums\DeliveryMethod::PICKUP,
            $this->user->fresh()->default_delivery_method
        );
    }

    public function test_checkout_index_prefills_default_address_and_method(): void
    {
        $this->user->update(['default_delivery_method' => \App\Enums\DeliveryMethod::PICKUP]);

        \App\Models\DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => false,
        ]);
        $default = \App\Models\DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => true,
        ]);

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

        $response = $this->actingAs($this->user)->get('/checkout');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/Checkout/Index')
            ->where('defaultDeliveryMethod', 'pickup')
            ->where('addresses.0.id', $default->id)
            ->where('addresses.0.is_default', true)
        );
    }
}
