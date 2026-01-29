<?php

namespace Tests\Feature\Api;

use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Currency;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_checkout_with_in_stock_items()
    {
        $user = User::factory()->create();
        
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['base_price' => 100]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $company = Company::factory()->create(['user_id' => $user->id]);
        $address = DeliveryAddress::factory()->create(['user_id' => $user->id]);

        // Mock StockService to return available stock
        $stockMock = Mockery::mock(StockServiceInterface::class);
        $stockMock->shouldReceive('getStock')
            ->andReturn(['available' => 10, 'preorder' => 0]);
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($user)
            ->postJson('/api/orders/checkout', [
                'cart_id' => $cart->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
                'comment' => 'Test comment',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Order created successfully');

        // Parent order should exist with total
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 200,
            'type' => OrderType::STANDARD->value,
        ]);

        // Child order should exist
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 200,
            'type' => OrderType::IN_STOCK->value,
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'price' => 100,
            'quantity' => 2,
            'subtotal' => 200,
        ]);
    }

    public function test_order_splits_into_in_stock_and_preorder()
    {
        $user = User::factory()->create();
        
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['base_price' => 100]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 5, // Request 5, only 2 in stock, 3 in preorder
        ]);

        $company = Company::factory()->create(['user_id' => $user->id]);
        $address = DeliveryAddress::factory()->create(['user_id' => $user->id]);

        // Mock StockService: 2 available, 3 preorder
        $stockMock = Mockery::mock(StockServiceInterface::class);
        $stockMock->shouldReceive('getStock')
            ->andReturn(['available' => 2, 'preorder' => 3]);
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($user)
            ->postJson('/api/orders/checkout', [
                'cart_id' => $cart->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
            ]);

        $response->assertStatus(201);

        // Parent order
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 500, // 2*100 + 3*100
            'type' => OrderType::STANDARD->value,
        ]);

        // In-stock child: 2 items
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 200,
            'type' => OrderType::IN_STOCK->value,
        ]);

        // Preorder child: 3 items
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 300,
            'type' => OrderType::PREORDER->value,
        ]);
    }

    public function test_order_splits_correctly_with_partial_preorder()
    {
        // Scenario: 2 in stock, 2 preorder available, user orders 3
        // Expected: IN_STOCK = 2, PREORDER = 1
        $user = User::factory()->create();
        
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['base_price' => 100]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $company = Company::factory()->create(['user_id' => $user->id]);
        $address = DeliveryAddress::factory()->create(['user_id' => $user->id]);

        // Mock StockService: 2 available, 2 preorder
        $stockMock = Mockery::mock(StockServiceInterface::class);
        $stockMock->shouldReceive('getStock')
            ->andReturn(['available' => 2, 'preorder' => 2]);
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($user)
            ->postJson('/api/orders/checkout', [
                'cart_id' => $cart->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
            ]);

        $response->assertStatus(201);

        // Parent order: total = 2*100 + 1*100 = 300
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 300,
            'type' => OrderType::STANDARD->value,
        ]);

        // In-stock child: 2 items = 200
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'subtotal' => 200,
        ]);

        // Preorder child: 1 item = 100
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 1,
            'subtotal' => 100,
        ]);
    }

    public function test_checkout_fails_when_insufficient_stock()
    {
        // Scenario: 2 in stock, 2 preorder available, user orders 10
        // Expected: Rejection with 422
        $user = User::factory()->create();
        
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['base_price' => 100]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $company = Company::factory()->create(['user_id' => $user->id]);
        $address = DeliveryAddress::factory()->create(['user_id' => $user->id]);

        // Mock StockService: 2 available, 2 preorder (total = 4)
        $stockMock = Mockery::mock(StockServiceInterface::class);
        $stockMock->shouldReceive('getStock')
            ->andReturn(['available' => 2, 'preorder' => 2]);
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $response = $this->actingAs($user)
            ->postJson('/api/orders/checkout', [
                'cart_id' => $cart->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock for some items')
            ->assertJsonStructure(['errors' => [['product', 'requested', 'available']]]);
        
        // No orders should be created
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_user_can_view_own_orders()
    {
        $user = User::factory()->create();
        Order::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/orders');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_user_cannot_view_others_orders()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Order::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/orders');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
