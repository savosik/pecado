<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $admin;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a base currency
        Currency::create([
            'code' => 'RUB',
            'name' => 'Russian Ruble',
            'symbol' => '₽',
            'is_base' => true,
            'exchange_rate' => 1,
            'correction_factor' => 1,
        ]);

        // Create a region
        $region = Region::create(['name' => 'Russia']);

        $this->user = User::factory()->create([
            'region_id' => $region->id,
            'is_admin' => false,
        ]);
        $this->admin = User::factory()->create([
            'region_id' => $region->id,
            'is_admin' => true,
        ]);
        $this->otherUser = User::factory()->create([
            'region_id' => $region->id,
            'is_admin' => false,
        ]);
    }

    #[Test]
    public function user_can_list_own_carts(): void
    {
        Cart::factory()->count(2)->create(['user_id' => $this->user->id]);
        Cart::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/api/carts');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function admin_can_see_all_carts(): void
    {
        Cart::factory()->create(['user_id' => $this->user->id]);
        Cart::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/carts');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function user_cannot_see_other_user_carts(): void
    {
        Cart::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/api/carts');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    #[Test]
    public function user_can_create_cart(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/carts', [
            'name' => 'My Wishlist',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('cart.name', 'My Wishlist');

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'name' => 'My Wishlist',
        ]);
    }

    #[Test]
    public function user_can_create_cart_without_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/carts', []);

        $response->assertCreated();
        $response->assertJsonPath('cart.name', 'Корзина');
    }

    #[Test]
    public function user_can_view_own_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/api/carts/{$cart->id}");

        $response->assertOk();
        $response->assertJsonPath('cart.id', $cart->id);
    }

    #[Test]
    public function user_cannot_view_other_user_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson("/api/carts/{$cart->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function user_can_rename_own_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'name' => 'Old Name']);

        $response = $this->actingAs($this->user)->putJson("/api/carts/{$cart->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('cart.name', 'New Name');
    }

    #[Test]
    public function user_cannot_rename_other_user_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->putJson("/api/carts/{$cart->id}", [
            'name' => 'New Name',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function user_can_delete_own_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/carts/{$cart->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
    }

    #[Test]
    public function user_cannot_delete_other_user_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/carts/{$cart->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function user_can_add_product_to_own_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $product = Product::create(['name' => 'Test Product', 'base_price' => 100]);

        $response = $this->actingAs($this->user)->postJson("/api/carts/{$cart->id}/items", [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('item.quantity', 2);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    #[Test]
    public function adding_existing_product_increases_quantity(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $product = Product::create(['name' => 'Test Product', 'base_price' => 100]);
        
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/carts/{$cart->id}/items", [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response->assertOk();
        $response->assertJsonPath('item.quantity', 4);
    }

    #[Test]
    public function user_can_update_item_quantity(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $product = Product::create(['name' => 'Test Product', 'base_price' => 100]);
        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/carts/{$cart->id}/items/{$item->id}", [
            'quantity' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('item.quantity', 5);
    }

    #[Test]
    public function user_can_remove_product_from_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $product = Product::create(['name' => 'Test Product', 'base_price' => 100]);
        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/carts/{$cart->id}/items/{$item->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    #[Test]
    public function user_cannot_add_product_to_other_user_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->otherUser->id]);
        $product = Product::create(['name' => 'Test Product', 'base_price' => 100]);

        $response = $this->actingAs($this->user)->postJson("/api/carts/{$cart->id}/items", [
            'product_id' => $product->id,
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function cart_shows_correct_summary(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $product = Product::create(['name' => 'Test Product', 'base_price' => 100.50]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/carts/{$cart->id}");

        $response->assertOk();
        $response->assertJsonPath('cart.summary.items_count', 2);
        $this->assertEquals(201.0, $response->json('cart.summary.total_price'));
    }
}
