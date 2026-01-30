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

class CartMoveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Cart $sourceCart;
    private Cart $targetCart;
    private Product $product1;
    private Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup base currency and region if needed (borrowed from CartControllerTest)
        Currency::create(['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽', 'is_base' => true, 'exchange_rate' => 1, 'correction_factor' => 1]);
        $region = Region::create(['name' => 'Russia']);

        $this->user = User::factory()->create([
            'region_id' => $region->id,
            'is_admin' => false,
        ]);

        $this->sourceCart = Cart::factory()->create(['user_id' => $this->user->id, 'name' => 'Source Cart']);
        $this->targetCart = Cart::factory()->create(['user_id' => $this->user->id, 'name' => 'Target Cart']);

        $this->product1 = Product::create(['name' => 'Product 1', 'base_price' => 100]);
        $this->product2 = Product::create(['name' => 'Product 2', 'base_price' => 200]);

        // Add items to source cart
        CartItem::create(['cart_id' => $this->sourceCart->id, 'product_id' => $this->product1->id, 'quantity' => 2]);
        CartItem::create(['cart_id' => $this->sourceCart->id, 'product_id' => $this->product2->id, 'quantity' => 1]);
    }

    #[Test]
    public function user_can_move_all_items_to_another_cart(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/carts/{$this->sourceCart->id}/move", [
            'target_cart_id' => $this->targetCart->id,
        ]);

        $response->assertOk();

        // Source cart should be empty
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $this->sourceCart->id]);

        // Target cart should have items
        $this->assertDatabaseHas('cart_items', ['cart_id' => $this->targetCart->id, 'product_id' => $this->product1->id, 'quantity' => 2]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $this->targetCart->id, 'product_id' => $this->product2->id, 'quantity' => 1]);
    }

    #[Test]
    public function user_can_move_specific_items_to_another_cart(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/carts/{$this->sourceCart->id}/move", [
            'target_cart_id' => $this->targetCart->id,
            'product_ids' => [$this->product1->id],
        ]);

        $response->assertOk();

        // Product 1 should be moved
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $this->sourceCart->id, 'product_id' => $this->product1->id]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $this->targetCart->id, 'product_id' => $this->product1->id, 'quantity' => 2]);

        // Product 2 should remain
        $this->assertDatabaseHas('cart_items', ['cart_id' => $this->sourceCart->id, 'product_id' => $this->product2->id]);
    }

    #[Test]
    public function quantities_are_merged_when_moving_items(): void
    {
        // Add existing item to target cart
        CartItem::create(['cart_id' => $this->targetCart->id, 'product_id' => $this->product1->id, 'quantity' => 3]);

        $response = $this->actingAs($this->user)->postJson("/api/carts/{$this->sourceCart->id}/move", [
            'target_cart_id' => $this->targetCart->id,
            'product_ids' => [$this->product1->id],
        ]);

        $response->assertOk();

        // Source cart should not have product 1
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $this->sourceCart->id, 'product_id' => $this->product1->id]);

        // Target cart should have combined quantity (3 + 2 = 5)
        $this->assertDatabaseHas('cart_items', ['cart_id' => $this->targetCart->id, 'product_id' => $this->product1->id, 'quantity' => 5]);
    }

    #[Test]
    public function user_cannot_move_items_to_another_users_cart(): void
    {
        $otherUser = User::factory()->create();
        $otherCart = Cart::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->postJson("/api/carts/{$this->sourceCart->id}/move", [
            'target_cart_id' => $otherCart->id,
        ]);

        $response->assertForbidden();
    }
}
