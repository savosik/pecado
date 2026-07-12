<?php

namespace Tests\Feature\Services;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Events\OrderCreated;
use App\Events\OrderUpdated;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckoutServicePublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $priceServiceMock = $this->createMock(PriceServiceInterface::class);
        $priceServiceMock->method('getPriceResult')
            ->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $priceServiceMock->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $priceServiceMock);

        $stockServiceMock = $this->createMock(StockServiceInterface::class);
        $stockServiceMock->method('getStock')->willReturn(['available' => 100, 'preorder' => 100]);
        $this->app->instance(StockServiceInterface::class, $stockServiceMock);

        $currencyResolverMock = $this->createMock(UserCurrencyResolverInterface::class);
        $currencyResolverMock->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currencyResolverMock);
    }

    #[Test]
    public function checkout_не_публикует_лишний_order_updated_перед_order_created(): void
    {
        Event::fake([OrderCreated::class, OrderUpdated::class]);

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);

        $checkout = $this->app->make(CheckoutServiceInterface::class);
        $cart->load('items.product', 'user');

        $orders = $checkout->checkout($cart, $company, 'г. Москва, ул. Тестовая, д. 1');

        $this->assertCount(1, $orders);
        Event::assertDispatched(OrderCreated::class, 1);
        Event::assertNotDispatched(OrderUpdated::class);
    }

    #[Test]
    public function checkout_публикует_ровно_одно_order_created_сообщение_в_шину(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'u-erp-1']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create(['external_id' => 'p-erp-1']);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'item_type' => 'instock',
        ]);

        $checkout = $this->app->make(CheckoutServiceInterface::class);
        $cart->load('items.product', 'user');

        $checkout->checkout($cart, $company, 'г. Москва, ул. Тестовая, д. 1');

        Queue::assertPushed(PublishOrderToErpJob::class, 1);
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) {
            return ($job->payload['event'] ?? null) === 'order.created';
        });
    }

    #[Test]
    public function checkout_с_самовывозом_создаёт_заказ_без_адреса_и_публикует_pickup_в_шину(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'u-erp-pickup']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create(['external_id' => 'p-erp-pickup']);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);

        $checkout = $this->app->make(CheckoutServiceInterface::class);
        $cart->load('items.product', 'user');

        $orders = $checkout->checkout(
            $cart,
            $company,
            null,
            null,
            null,
            null,
            \App\Enums\DeliveryMethod::PICKUP,
        );

        $this->assertCount(1, $orders);
        $order = $orders->first()->fresh();
        $this->assertSame(\App\Enums\DeliveryMethod::PICKUP, $order->delivery_method);
        $this->assertNull($order->delivery_address);

        $validator = app(\App\Services\Erp\ErpMessageValidator::class);

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($validator) {
            return ($job->payload['delivery_method'] ?? null) === 'pickup'
                && $job->payload['delivery_address'] === null
                && $validator->validateOutbound('order.created', $job->payload)['valid'] === true;
        });
    }

    #[Test]
    public function checkout_с_самовывозом_разбивает_корзину_на_два_pickup_заказа(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 3,
            'item_type' => 'preorder',
        ]);

        $checkout = $this->app->make(CheckoutServiceInterface::class);
        $cart->load('items.product', 'user');

        $orders = $checkout->checkout(
            $cart,
            $company,
            'этот адрес должен быть проигнорирован',
            null,
            null,
            null,
            \App\Enums\DeliveryMethod::PICKUP,
        );

        $this->assertCount(2, $orders);
        foreach ($orders as $order) {
            $fresh = $order->fresh();
            $this->assertSame(\App\Enums\DeliveryMethod::PICKUP, $fresh->delivery_method);
            $this->assertNull($fresh->delivery_address);
        }
    }
}
