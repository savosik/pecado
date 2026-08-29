<?php

namespace Tests\Feature\Order;

use App\Enums\OrderType;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Кнопка «Только со склада» на чекауте.
 *
 * Клиент, не желающий ждать поставку, оформляет наличие одним кликом:
 * предзаказные строки корзины не превращаются в заказ-близнец и удаляются.
 * Обычный путь («Заказ + предзаказ») по-прежнему даёт два документа.
 */
class InstockOnlyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOrderToErpJob::class]);

        $primary = Warehouse::factory()->create(['name' => 'Основной']);
        $preorder = Warehouse::factory()->create(['name' => 'Предзаказный']);
        $region = Region::factory()->create(['name' => 'Тестовый регион']);

        foreach ([[$primary->id, 'primary'], [$preorder->id, 'preorder']] as [$warehouseId, $type]) {
            DB::table('region_warehouse')->insert([
                'region_id' => $region->id,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->user = User::factory()->create(['region_id' => $region->id]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);

        $this->product = Product::factory()->create(['base_price' => 1000]);
        DB::table('product_warehouse')->insert([
            ['product_id' => $this->product->id, 'warehouse_id' => $primary->id, 'quantity' => 6],
            ['product_id' => $this->product->id, 'warehouse_id' => $preorder->id, 'quantity' => 10],
        ]);
    }

    private function cartWith(int $instock, int $preorder): Cart
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        if ($instock > 0) {
            CartItem::factory()->create([
                'cart_id' => $cart->id, 'product_id' => $this->product->id,
                'quantity' => $instock, 'price' => 1000, 'item_type' => 'instock',
            ]);
        }
        if ($preorder > 0) {
            CartItem::factory()->create([
                'cart_id' => $cart->id, 'product_id' => $this->product->id,
                'quantity' => $preorder, 'price' => 1000, 'item_type' => 'preorder',
            ]);
        }

        return $cart;
    }

    private function checkout(array $extra = [])
    {
        return $this->actingAs($this->user)->post('/checkout', array_merge([
            'company_id' => $this->company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
        ], $extra));
    }

    #[Test]
    public function default_checkout_creates_order_and_preorder_twin(): void
    {
        $this->cartWith(6, 4);

        $this->checkout()->assertRedirect(route('cabinet.orders.index'));

        $this->assertSame(2, Order::count());
        $this->assertDatabaseHas('orders', ['user_id' => $this->user->id, 'type' => OrderType::ORDER->value]);
        $this->assertDatabaseHas('orders', ['user_id' => $this->user->id, 'type' => OrderType::PREORDER->value]);
    }

    #[Test]
    public function success_message_names_the_preorder_and_its_lead_time(): void
    {
        config(['preorder.lead_days' => ['min' => 7, 'max' => 9]]);
        $this->cartWith(6, 4);

        $response = $this->checkout();

        $preorder = Order::where('type', OrderType::PREORDER->value)->firstOrFail();
        $response->assertSessionHas('success', fn (string $m) => str_contains($m, $preorder->number) && str_contains($m, '7–9 дней'));
    }

    #[Test]
    public function instock_only_creates_single_order_and_drops_preorder_rows(): void
    {
        $cart = $this->cartWith(6, 4);

        $this->checkout(['instock_only' => 1])->assertRedirect();

        $this->assertSame(1, Order::count());
        $order = Order::firstOrFail();
        $this->assertSame(OrderType::ORDER, $order->type);
        $this->assertSame(6, (int) $order->items()->sum('quantity'));
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id, 'item_type' => 'preorder']);
        $this->assertDatabaseMissing('orders', ['type' => OrderType::PREORDER->value]);
    }

    #[Test]
    public function instock_only_with_preorder_only_cart_is_rejected_with_explanation(): void
    {
        $cart = $this->cartWith(0, 4);

        $this->checkout(['instock_only' => 1])->assertSessionHasErrors('stock');

        $this->assertSame(0, Order::count());
        // Корзину не трогаем: клиент вернётся на чекаут и выберет «Оформить предзаказ».
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'item_type' => 'preorder', 'quantity' => 4]);
    }

    #[Test]
    public function opted_out_client_never_gets_a_preorder_document(): void
    {
        // Флаг выключили, когда предзаказная строка уже лежала в корзине.
        $this->user->forceFill(['preorders_enabled' => false])->save();
        $this->cartWith(6, 4);

        $this->checkout()->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame(OrderType::ORDER, Order::firstOrFail()->type);
    }
}
