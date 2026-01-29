<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Currency;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_checkout_from_cart()
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

        $response = $this->actingAs($user)
            ->postJson('/api/orders/checkout', [
                'cart_id' => $cart->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
                'comment' => 'Test comment',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Order created successfully');

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 200,
            'comment' => 'Test comment',
            'currency_code' => 'RUB',
            'exchange_rate' => 1.0,
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'price' => 100,
            'quantity' => 2,
            'subtotal' => 200,
        ]);
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
