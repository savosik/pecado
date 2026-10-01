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
use App\Services\Order\OrderWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Топик №17 Agent Hub, заказ 29УТ-014795 (23.09.2026).
 *
 * Строка корзины получает вид «в наличии / предзаказ» при добавлении, а склад
 * заказа выводится из вида: наличие — основной склад региона, предзаказ — склад
 * предзаказа (для Москвы — «Тюмень Основной»). Чекаут сверял строку с суммой
 * складов, и строка «в наличии» при Москве 0 прошла по остатку Тюмени — заказ
 * ушёл на «Москва Основной», где товара не было. Сценарий здесь на реальном
 * StockService и реальных складах региона.
 */
class CheckoutStockByLineTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $primary;

    private Warehouse $preorderWarehouse;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOrderToErpJob::class]);
        // Костыль подмены UUID склада предзаказа к сценарию не относится.
        config(['erp.preorder_warehouse_uuid_override.enabled' => false]);

        $this->primary = Warehouse::factory()->create(['name' => 'Москва Основной']);
        $this->preorderWarehouse = Warehouse::factory()->create(['name' => 'Тюмень Основной']);

        $region = Region::factory()->create(['name' => 'Москва']);
        foreach (['primary' => $this->primary, 'preorder' => $this->preorderWarehouse] as $type => $warehouse) {
            DB::table('region_warehouse')->insert([
                'region_id' => $region->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->user = User::factory()->create(['region_id' => $region->id]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
        $this->cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
    }

    private function product(int $primary, int $preorder): Product
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $this->primary->id, 'quantity' => $primary],
            ['product_id' => $product->id, 'warehouse_id' => $this->preorderWarehouse->id, 'quantity' => $preorder],
        ]);

        return $product;
    }

    private function line(Product $product, int $quantity, string $type): CartItem
    {
        return CartItem::factory()->create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->base_price,
            'item_type' => $type,
        ]);
    }

    private function submit()
    {
        return $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $this->company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
        ]);
    }

    #[Test]
    #[TestDox('29УТ-014795: строка «в наличии» при основном складе 0 не проходит по остатку склада предзаказа')]
    public function instock_line_is_not_covered_by_preorder_warehouse(): void
    {
        $product = $this->product(primary: 0, preorder: 37);
        $line = $this->line($product, 1, 'instock');

        $this->submit()
            ->assertRedirect()
            ->assertSessionHasErrors('stock')
            ->assertSessionHas('stock_conflicts', fn (array $items) => count($items) === 1
                && $items[0]['cart_item_id'] === $line->id
                && $items[0]['item_type'] === 'instock'
                && $items[0]['requested'] === 1
                && $items[0]['available'] === 0
                && $items[0]['available_total'] === 37);

        $this->assertSame(0, Order::count(), 'заказ на «Москва Основной» без остатка не создаётся');
    }

    #[Test]
    #[TestDox('29УТ-014795: превью помечает строку, normalize переводит её в предзаказ, заказ уходит на склад предзаказа')]
    public function normalize_moves_shortage_to_preorder_and_order_goes_to_preorder_warehouse(): void
    {
        $product = $this->product(primary: 0, preorder: 37);
        $this->line($product, 1, 'instock');

        $this->actingAs($this->user)->get('/checkout')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instockItems.0.stock_status', 'unavailable')
                ->where('instockItems.0.line_available', 0)
                ->where('instockItems.0.max_total', 37));

        $this->actingAs($this->user)->post('/checkout/normalize-stock')
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'в предзаказ переведено 1 шт.'));

        $lines = $this->cart->items()->get();
        $this->assertCount(1, $lines);
        $this->assertSame('preorder', $lines[0]->item_type);
        $this->assertSame(1, (int) $lines[0]->quantity);
        $this->assertEquals(1000, (float) $lines[0]->price, 'цена строки сохраняется');

        $this->submit()->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::sole();
        $this->assertSame(OrderType::PREORDER, $order->type);
        $this->assertSame(
            [$this->preorderWarehouse->external_id],
            app(OrderWarehouseResolver::class)->resolve($order->fresh('user')),
            'предзаказ уходит на склад предзаказа, а не на основной',
        );
    }

    #[Test]
    #[TestDox('Частичная нехватка: в наличии остаётся сколько есть на основном складе, остальное — в предзаказ')]
    public function partial_shortage_is_split(): void
    {
        $product = $this->product(primary: 2, preorder: 10);
        $this->line($product, 5, 'instock');

        $this->submit()->assertSessionHasErrors('stock');

        $this->actingAs($this->user)->post('/checkout/normalize-stock')->assertRedirect(route('checkout.index'));

        $this->assertSame(
            ['instock' => 2, 'preorder' => 3],
            $this->cart->items()->pluck('quantity', 'item_type')->map(fn ($q) => (int) $q)->sortKeys()->all(),
        );

        $this->submit()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(
            [OrderType::ORDER, OrderType::PREORDER],
            Order::pluck('type')->all(),
        );
    }

    #[Test]
    #[TestDox('Зеркально: предзаказ сверх склада предзаказа не проходит по остатку основного склада')]
    public function preorder_line_is_not_covered_by_primary_warehouse(): void
    {
        $product = $this->product(primary: 20, preorder: 1);
        $this->line($product, 3, 'preorder');

        $this->submit()
            ->assertSessionHasErrors('stock')
            ->assertSessionHas('stock_conflicts', fn (array $items) => $items[0]['item_type'] === 'preorder'
                && $items[0]['available'] === 1);

        // Переразбивка как при вводе количества: основной склад покрывает всё —
        // товар уходит заказом со склада, предзаказ не нужен.
        $this->actingAs($this->user)->post('/checkout/normalize-stock')->assertRedirect(route('checkout.index'));
        $this->assertSame(
            ['instock' => 3],
            $this->cart->items()->pluck('quantity', 'item_type')->map(fn ($q) => (int) $q)->all(),
        );

        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(OrderType::ORDER, Order::sole()->type);
    }

    #[Test]
    #[TestDox('Клиент с выключенными предзаказами: нехватка не переносится в предзаказ, а снимается')]
    public function client_without_preorders_gets_shortage_removed(): void
    {
        $this->user->forceFill(['preorders_enabled' => false])->save();

        $short = $this->product(primary: 0, preorder: 37);
        $partial = $this->product(primary: 2, preorder: 37);
        $this->line($short, 1, 'instock');
        $this->line($partial, 5, 'instock');

        $this->submit()->assertSessionHasErrors('stock');

        $this->actingAs($this->user)->post('/checkout/normalize-stock')
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'предзаказ'));

        $lines = $this->cart->items()->get();
        $this->assertCount(1, $lines);
        $this->assertSame([$partial->id, 'instock', 2], [$lines[0]->product_id, $lines[0]->item_type, (int) $lines[0]->quantity]);

        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(OrderType::ORDER, Order::sole()->type);
    }

    #[Test]
    #[TestDox('Строки в пределах своих складов оформляются без изменений')]
    public function lines_within_own_stock_pass(): void
    {
        $product = $this->product(primary: 1, preorder: 2);
        $this->line($product, 1, 'instock');
        $this->line($product, 2, 'preorder');

        $this->actingAs($this->user)->post('/checkout/normalize-stock')->assertSessionHas('info');

        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(2, Order::count());
    }
}
