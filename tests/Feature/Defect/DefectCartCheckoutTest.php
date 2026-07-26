<?php

namespace Tests\Feature\Defect;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DefectCartCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Обычные цены/остатки мокаем — здесь важна только уценка.
        $priceMock = $this->createMock(PriceServiceInterface::class);
        $priceMock->method('getPriceResult')->willReturn(new PriceResult(100.0, 100.0, 0.0, false));
        $priceMock->method('getUserPrice')->willReturn(100.0);
        $priceMock->method('getBasePriceForUser')->willReturn(100.0);
        $this->app->instance(PriceServiceInterface::class, $priceMock);

        $stockMock = $this->createMock(StockServiceInterface::class);
        $stockMock->method('getStock')->willReturn(['available' => 100, 'preorder' => 0]);
        $this->app->instance(StockServiceInterface::class, $stockMock);
    }

    private function cartService(): CartServiceInterface
    {
        return $this->app->make(CartServiceInterface::class);
    }

    private function sellableDefect(int $quantity = 5, float $price = 499.0): ProductDefect
    {
        return ProductDefect::factory()->sellable($price)->create(['quantity' => $quantity]);
    }

    // ────────────────────────────────────────────
    // Корзина
    // ────────────────────────────────────────────

    #[Test]
    public function defect_added_to_cart_as_separate_line(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(5, 499);

        $result = $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);

        $this->assertSame(2, $result['quantity']);

        $item = $cart->items()->first();
        $this->assertSame('defect', $item->item_type);
        $this->assertSame($defect->id, $item->product_defect_id);
        $this->assertSame($defect->product_id, $item->product_id);
        $this->assertEquals(499.0, (float) $item->price);
    }

    #[Test]
    public function defect_quantity_is_clamped_to_available(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(3, 200);

        $result = $this->cartService()->setDefectQuantity($user, $cart, $defect, 10);

        $this->assertSame(3, $result['quantity'], 'Нельзя добавить больше, чем в партии');
    }

    #[Test]
    public function unpublished_defect_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = ProductDefect::factory()->priced(200)->create(['quantity' => 5]); // не опубликована

        $result = $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);

        $this->assertSame(0, $result['quantity']);
        $this->assertSame(0, $cart->items()->count());
    }

    #[Test]
    public function changing_regular_product_quantity_keeps_defect_line(): void
    {
        // Главный риск PR: spillover обычного товара не должен стирать уценку.
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 4]);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);
        $this->cartService()->setProductQuantity($user, $cart, $product, 5);

        $this->assertSame(1, $cart->items()->defect()->count(), 'Уценка должна уцелеть');
        $this->assertSame(2, (int) $cart->items()->defect()->first()->quantity);
        $this->assertSame(1, $cart->items()->where('item_type', 'instock')->count());
    }

    #[Test]
    public function zeroing_regular_product_keeps_defect_line(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 4]);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);
        $this->cartService()->setProductQuantity($user, $cart, $product, 3);
        $this->cartService()->setProductQuantity($user, $cart, $product, 0);

        $this->assertSame(1, $cart->items()->defect()->count());
    }

    #[Test]
    public function setting_defect_quantity_to_zero_removes_line(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(5, 100);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);
        $this->cartService()->setDefectQuantity($user, $cart, $defect, 0);

        $this->assertSame(0, $cart->items()->count());
    }

    #[Test]
    public function two_defects_of_same_product_are_separate_lines(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        $a = ProductDefect::factory()->for($product)->sellable(100)->create(['quantity' => 2]);
        $b = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 2]);

        $this->cartService()->setDefectQuantity($user, $cart, $a, 1);
        $this->cartService()->setDefectQuantity($user, $cart, $b, 1);

        $this->assertSame(2, $cart->items()->defect()->count());
    }

    // ────────────────────────────────────────────
    // Эндпоинт set-defect-quantity + снапшот (store)
    // ────────────────────────────────────────────

    #[Test]
    public function set_defect_quantity_endpoint_sets_and_removes(): void
    {
        $user = User::factory()->create(['status' => \App\Enums\UserStatus::ACTIVE]);
        $defect = $this->sellableDefect(5, 300);

        $this->actingAs($user)
            ->postJson('/api/cart/set-defect-quantity', ['defect_id' => $defect->id, 'quantity' => 3])
            ->assertOk()
            ->assertJson(['status' => 'success', 'quantity' => 3]);

        $cart = $user->carts()->active()->first();
        $this->assertSame(3, (int) $cart->items()->defect()->first()->quantity);

        // 0 = удалить позицию.
        $this->actingAs($user)
            ->postJson('/api/cart/set-defect-quantity', ['defect_id' => $defect->id, 'quantity' => 0])
            ->assertOk()
            ->assertJson(['quantity' => 0]);

        $this->assertSame(0, $cart->items()->defect()->count());
    }

    #[Test]
    public function set_defect_quantity_is_clamped_to_available(): void
    {
        $user = User::factory()->create(['status' => \App\Enums\UserStatus::ACTIVE]);
        $defect = $this->sellableDefect(2, 300);

        $this->actingAs($user)
            ->postJson('/api/cart/set-defect-quantity', ['defect_id' => $defect->id, 'quantity' => 9])
            ->assertOk()
            ->assertJson(['quantity' => 2]);
    }

    #[Test]
    public function active_quantities_snapshot_exposes_defect_quantities(): void
    {
        $user = User::factory()->create(['status' => \App\Enums\UserStatus::ACTIVE]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(5, 300);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);

        $this->actingAs($user)
            ->getJson('/api/cart/active-quantities')
            ->assertOk()
            ->assertJsonPath("defect_quantities.{$defect->id}", 2);
    }

    // ────────────────────────────────────────────
    // Checkout
    // ────────────────────────────────────────────

    #[Test]
    public function checkout_creates_separate_defect_order(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create();
        $defect = ProductDefect::factory()->for($product)->sellable(499)->create(['quantity' => 5]);

        // instock + defect в одной корзине → должно получиться два заказа.
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'item_type' => 'instock',
        ]);
        $this->cartService()->setDefectQuantity($user, $cart, $defect, 3);

        $cart->load('items.product', 'items.productDefect', 'user');
        $orders = $this->app->make(CheckoutServiceInterface::class)
            ->checkout($cart, $company, 'г. Москва, ул. Тестовая, д. 1');

        $this->assertCount(2, $orders);

        $defectOrder = $orders->firstWhere('type', OrderType::DEFECT);
        $this->assertNotNull($defectOrder);

        $item = $defectOrder->items()->first();
        $this->assertSame($defect->id, $item->product_defect_id);
        $this->assertSame($defect->defect_description, $item->defect_description);
        $this->assertEquals(499.0, (float) $item->final_price);
        $this->assertEquals(3 * 499.0, (float) $defectOrder->total_amount);
    }

    #[Test]
    public function defect_order_comment_lists_pick_details_for_warehouse(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create(['sku' => 'МН-777']);

        // Тип дефекта известен → его id попадёт в скобки; «трещина» — свободный
        // текст без совпадения, остаётся только текстом.
        $type = \App\Models\DefectType::firstOrCreate(['name' => 'Вскрыта упаковка'], ['is_active' => true, 'sort_order' => 1]);

        $defect = ProductDefect::factory()->for($product)->sellable(499)
            ->create(['quantity' => 5, 'defect_description' => 'Вскрыта упаковка; трещина']);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 3);
        $cart->load('items.product', 'items.productDefect', 'user');

        $orders = $this->app->make(CheckoutServiceInterface::class)
            ->checkout($cart, $company, 'г. Москва', warehouseComment: 'Позвонить перед отгрузкой');

        $defectOrder = $orders->firstWhere('type', OrderType::DEFECT);
        $comment = $defectOrder->warehouse_comment;

        // Ручной комментарий сохранён, блок отбора дописан.
        $this->assertStringContainsString('Позвонить перед отгрузкой', $comment);
        $this->assertStringContainsString(\App\Services\Defect\DefectPickListFormatter::HEADING, $comment);
        $this->assertStringContainsString("арт. МН-777 — партия #{$defect->id}", $comment);
        $this->assertStringContainsString("дефекты [{$type->id}]: Вскрыта упаковка, трещина", $comment);
        $this->assertStringContainsString('— 3 шт.', $comment);
    }

    #[Test]
    public function defect_order_comment_is_added_even_without_manual_comment(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $product = Product::factory()->create(['sku' => 'МН-555']);
        $defect = ProductDefect::factory()->for($product)->sellable(300)
            ->create(['quantity' => 5, 'defect_description' => 'Помята коробка']);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 1);
        $cart->load('items.product', 'items.productDefect', 'user');

        $orders = $this->app->make(CheckoutServiceInterface::class)
            ->checkout($cart, $company, 'г. Москва');

        $comment = $orders->firstWhere('type', OrderType::DEFECT)->warehouse_comment;
        $this->assertStringStartsWith(\App\Services\Defect\DefectPickListFormatter::HEADING, $comment);
        $this->assertStringContainsString('дефекты: Помята коробка', $comment);
    }

    #[Test]
    public function defect_order_reserves_the_batch(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(5, 300);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 3);
        $cart->load('items.product', 'items.productDefect', 'user');

        $this->app->make(CheckoutServiceInterface::class)
            ->checkout($cart, $company, 'г. Москва, ул. Тестовая, д. 1');

        $service = $this->app->make(\App\Contracts\Defect\DefectStockServiceInterface::class);
        $this->assertSame(3, $service->reserved($defect));
        $this->assertSame(2, $service->available($defect));
    }

    #[Test]
    public function checkout_only_defect_creates_single_defect_order(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(5, 300);

        $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);
        $cart->load('items.product', 'items.productDefect', 'user');

        $orders = $this->app->make(CheckoutServiceInterface::class)
            ->checkout($cart, $company, 'г. Москва, ул. Тестовая, д. 1');

        $this->assertCount(1, $orders);
        $this->assertSame(OrderType::DEFECT, $orders->first()->type);
    }

    #[Test]
    public function checkout_rejects_defect_over_available(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $defect = $this->sellableDefect(2, 300);

        // Кладём 2 (всё, что есть), затем ужимаем партию до 1 через отдельный заказ-резерв.
        $this->cartService()->setDefectQuantity($user, $cart, $defect, 2);

        // Резервируем 2 другим заказом — в корзине остаётся неоплачиваемый излишек.
        $other = Order::factory()->create(['type' => OrderType::DEFECT]);
        $other->items()->create([
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'name' => 'Резерв',
            'price' => 300,
            'base_price' => 300,
            'discount_percent' => 0,
            'final_price' => 300,
            'quantity' => 2,
            'subtotal' => 600,
        ]);

        $cart->load('items.product', 'items.productDefect', 'user');

        $this->expectException(\App\Exceptions\InsufficientStockException::class);
        $this->app->make(CheckoutServiceInterface::class)
            ->checkout($cart, $company, 'г. Москва, ул. Тестовая, д. 1');
    }
}
