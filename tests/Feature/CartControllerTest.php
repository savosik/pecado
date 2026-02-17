<?php

namespace Tests\Feature;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private PriceServiceInterface $priceServiceMock;
    private StockServiceInterface $stockServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Mock PriceService
        $this->priceServiceMock = $this->createMock(PriceServiceInterface::class);
        $this->priceServiceMock->method('getUserPrice')->willReturn(100.0);
        $this->priceServiceMock->method('getBasePrice')->willReturn(120.0);
        $this->priceServiceMock->method('getDiscountedPrice')->willReturn(100.0);
        $this->priceServiceMock->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $this->priceServiceMock);

        // Mock StockService
        $this->stockServiceMock = $this->createMock(StockServiceInterface::class);
        $this->stockServiceMock->method('getStock')->willReturn(['available' => 10, 'preorder' => 5]);
        $this->stockServiceMock->method('getAvailableStock')->willReturn(10);
        $this->stockServiceMock->method('getPreorderStock')->willReturn(5);
        $this->app->instance(StockServiceInterface::class, $this->stockServiceMock);
    }

    // ─── API: Summary ─────────────────────────────────────

    public function test_summary_returns_cart_items(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'quantity' => 3]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'quantity' => 2]);

        $response = $this->actingAs($this->user)->getJson('/api/cart/summary');

        $response->assertOk()
            ->assertJsonStructure(['items', 'total_quantity'])
            ->assertJsonPath('total_quantity', 5);
    }

    public function test_summary_creates_cart_if_none_exists(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/cart/summary');

        $response->assertOk()
            ->assertJsonPath('total_quantity', 0);

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id, 'is_active' => true]);
    }

    public function test_summary_requires_auth(): void
    {
        $response = $this->getJson('/api/cart/summary');
        $response->assertUnauthorized();
    }

    // ─── API: Active Quantities ────────────────────────────

    public function test_active_quantities_returns_product_qty_map(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product1->id,
            'quantity' => 3,
            'item_type' => 'instock',
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product1->id,
            'quantity' => 2,
            'item_type' => 'preorder',
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product2->id,
            'quantity' => 7,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/cart/active-quantities');

        $response->assertOk();

        $data = $response->json();
        $this->assertEquals(5, $data[$product1->id]);
        $this->assertEquals(7, $data[$product2->id]);
    }

    // ─── API: Set Product Quantity (Spillover) ─────────────

    public function test_set_product_quantity_with_spillover(): void
    {
        // Stock: available=10, preorder=5, max_total=15
        // Request qty=12 → instock=10, preorder=2, clamped=12
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();

        $response = $this->actingAs($this->user)->postJson('/api/cart/set-product-quantity', [
            'product_id' => $product->id,
            'quantity' => 12,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('instock', 10)
            ->assertJsonPath('preorder', 2)
            ->assertJsonPath('clamped', 12)
            ->assertJsonPath('max_total', 15);

        // Verify DB
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'item_type' => 'instock',
            'quantity' => 10,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'item_type' => 'preorder',
            'quantity' => 2,
        ]);
    }

    public function test_set_product_quantity_clamping(): void
    {
        // Stock: available=10, preorder=5, max_total=15
        // Request qty=20 → clamped to 15: instock=10, preorder=5
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();

        $response = $this->actingAs($this->user)->postJson('/api/cart/set-product-quantity', [
            'product_id' => $product->id,
            'quantity' => 20,
        ]);

        $response->assertOk()
            ->assertJsonPath('clamped', 15)
            ->assertJsonPath('instock', 10)
            ->assertJsonPath('preorder', 5);
    }

    public function test_set_product_quantity_zero_removes_items(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/cart/set-product-quantity', [
            'product_id' => $product->id,
            'quantity' => 0,
        ]);

        $response->assertOk()
            ->assertJsonPath('clamped', 0);

        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_set_product_quantity_includes_cart_totals(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();

        $response = $this->actingAs($this->user)->postJson('/api/cart/set-product-quantity', [
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'cart_totals' => [
                    'total_quantity',
                    'total_amount_regular',
                    'total_amount_discounted',
                ],
            ]);
    }

    // ─── API: Add Product ──────────────────────────────────

    public function test_add_product_creates_cart_items(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();

        $response = $this->actingAs($this->user)->postJson('/api/cart/add-product', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_add_product_default_quantity_is_one(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();

        $response = $this->actingAs($this->user)->postJson('/api/cart/add-product', [
            'product_id' => $product->id,
        ]);

        $response->assertStatus(201);

        $totalQty = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $this->assertEquals(1, $totalQty);
    }

    // ─── API: Add By Barcode ───────────────────────────────

    public function test_add_by_barcode_success(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => '1234567890123',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/cart/add-by-barcode', [
            'barcode' => '1234567890123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('product_id', $product->id);
    }

    public function test_add_by_barcode_not_found(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/cart/add-by-barcode', [
            'barcode' => '0000000000000',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('status', 'error');
    }

    // ─── API: Update Item ──────────────────────────────────

    public function test_update_item_quantity(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/cart/items/{$item->id}", [
            'quantity' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_update_item_forbidden_for_other_user(): void
    {
        $otherUser = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $otherUser->id]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id]);

        $response = $this->actingAs($this->user)->patchJson("/api/cart/items/{$item->id}", [
            'quantity' => 5,
        ]);

        $response->assertForbidden();
    }

    // ─── API: Remove Item ──────────────────────────────────

    public function test_remove_item(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/cart/items/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_remove_item_forbidden_for_other_user(): void
    {
        $otherUser = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $otherUser->id]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/cart/items/{$item->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
    }

    // ─── API: Move Items ───────────────────────────────────

    public function test_move_items_between_carts(): void
    {
        $cart1 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $cart2 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);
        $product = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $cart1->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/cart/move-items', [
            'target_cart_id' => $cart2->id,
            'product_ids' => [$product->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('moved_count', 1);

        // Item should now be in cart2
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart2->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        // Item should no longer be in cart1
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart1->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_move_items_requires_auth(): void
    {
        $response = $this->postJson('/api/cart/move-items', [
            'target_cart_id' => 1,
            'product_ids' => [1],
        ]);

        $response->assertUnauthorized();
    }

    public function test_move_items_forbidden_for_other_users_cart(): void
    {
        $otherUser = User::factory()->create();
        $myCart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $otherCart = Cart::factory()->create(['user_id' => $otherUser->id]);
        $product = Product::factory()->create();

        CartItem::factory()->create([
            'cart_id' => $myCart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/cart/move-items', [
            'target_cart_id' => $otherCart->id,
            'product_ids' => [$product->id],
        ]);

        $response->assertForbidden();
    }

    // ─── API: Clear Cart ───────────────────────────────────

    public function test_clear_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        CartItem::factory()->count(3)->create(['cart_id' => $cart->id]);

        $response = $this->actingAs($this->user)->deleteJson('/api/cart/clear');

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertEquals(0, $cart->items()->count());
    }

    // ─── Web: Create Cart ──────────────────────────────────

    public function test_create_cart(): void
    {
        Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        $response = $this->actingAs($this->user)->post('/cart', [
            'name' => 'Моя новая корзина',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'name' => 'Моя новая корзина',
            'is_active' => true,
        ]);

        // Old cart should be deactivated
        $this->assertEquals(1, Cart::where('user_id', $this->user->id)->where('is_active', true)->count());
    }

    // ─── Web: Switch Cart ──────────────────────────────────

    public function test_switch_active_cart(): void
    {
        $cart1 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $cart2 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);

        $response = $this->actingAs($this->user)->post("/cart/{$cart2->id}/switch");

        $response->assertRedirect();

        $this->assertFalse($cart1->fresh()->is_active);
        $this->assertTrue($cart2->fresh()->is_active);
    }

    // ─── Web: Rename Cart ──────────────────────────────────

    public function test_rename_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'name' => 'старое имя']);

        $response = $this->actingAs($this->user)->patch("/cart/{$cart->id}", [
            'name' => 'Новое имя',
        ]);

        $response->assertRedirect();
        $this->assertEquals('Новое имя', $cart->fresh()->name);
    }

    // ─── Web: Delete Cart ──────────────────────────────────

    public function test_delete_cart(): void
    {
        $cart1 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $cart2 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);

        $response = $this->actingAs($this->user)->delete("/cart/{$cart2->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('carts', ['id' => $cart2->id]);
    }

    public function test_cannot_delete_last_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        $response = $this->actingAs($this->user)->delete("/cart/{$cart->id}");

        $response->assertRedirect();
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_deleting_active_cart_activates_next(): void
    {
        $cart1 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $cart2 = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);

        $response = $this->actingAs($this->user)->delete("/cart/{$cart1->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('carts', ['id' => $cart1->id]);
        $this->assertTrue($cart2->fresh()->is_active);
    }

    // ─── Web: Cart Index Redirect ──────────────────────────

    public function test_cart_index_redirects_to_active_cart(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        $response = $this->actingAs($this->user)->get('/cart');

        $response->assertRedirect(route('cart.show', $cart));
    }

    public function test_cart_index_creates_cart_if_none(): void
    {
        $response = $this->actingAs($this->user)->get('/cart');

        $response->assertRedirect();

        $cart = Cart::where('user_id', $this->user->id)->first();
        $this->assertNotNull($cart);
        $this->assertTrue($cart->is_active);
    }

    // ─── Web: Auth Required ────────────────────────────────

    public function test_cart_pages_require_auth(): void
    {
        $response = $this->get('/cart');
        $response->assertRedirect(route('login'));
    }

    public function test_cart_api_requires_auth(): void
    {
        $this->getJson('/api/cart/active-quantities')->assertUnauthorized();
        $this->postJson('/api/cart/set-product-quantity')->assertUnauthorized();
        $this->postJson('/api/cart/add-product')->assertUnauthorized();
        $this->postJson('/api/cart/add-by-barcode')->assertUnauthorized();
        $this->deleteJson('/api/cart/clear')->assertUnauthorized();
    }

    // ─── Validation ────────────────────────────────────────

    public function test_set_product_quantity_validation(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/cart/set-product-quantity', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'quantity']);
    }

    public function test_add_product_validation(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/cart/add-product', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }
}
