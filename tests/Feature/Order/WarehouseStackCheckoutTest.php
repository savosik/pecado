<?php

namespace Tests\Feature\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
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
 * Оформление в регионе со стопкой складов: товары, выигранные разными
 * складами, уходят отдельными заказами с зафиксированным складом
 * (assigned_warehouse_id) и общим checkout_uuid. Предзаказы и регионы
 * без стопки не затрагиваются.
 */
class WarehouseStackCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private User $user;

    private Company $company;

    private Warehouse $top;

    private Warehouse $bottom;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOrderToErpJob::class]);

        $this->region = Region::factory()->create(['stock_stack_enabled' => true]);
        $this->top = Warehouse::factory()->create(['name' => 'Верхний', 'external_id' => 'stack-top-uuid']);
        $this->bottom = Warehouse::factory()->create(['name' => 'Нижний', 'external_id' => 'stack-bottom-uuid']);

        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $this->top->id, 'type' => 'primary', 'priority' => 1],
            ['region_id' => $this->region->id, 'warehouse_id' => $this->bottom->id, 'type' => 'primary', 'priority' => 2],
        ]);

        $this->user = User::factory()->create(['region_id' => $this->region->id]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function product(array $stockByWarehouse): Product
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        foreach ($stockByWarehouse as $warehouseId => $quantity) {
            DB::table('product_warehouse')->insert([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
            ]);
        }

        return $product;
    }

    private function checkout(array $items, string $itemType = 'instock')
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        foreach ($items as [$product, $quantity]) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $product->base_price,
                'item_type' => $itemType,
            ]);
        }

        return app(CheckoutServiceInterface::class)->checkout(
            $cart->fresh(),
            $this->company,
            'г. Москва, ул. Тестовая, д. 1',
            null,
            null,
            null,
            DeliveryMethod::DELIVERY,
        );
    }

    #[Test]
    public function товары_разных_складов_стопки_уходят_отдельными_заказами(): void
    {
        $pTop = $this->product([$this->top->id => 5, $this->bottom->id => 100]);
        $pBottom = $this->product([$this->top->id => 0, $this->bottom->id => 7]);

        $orders = $this->checkout([[$pTop, 2], [$pBottom, 3]]);

        $this->assertCount(2, $orders);

        $byWarehouse = $orders->keyBy('assigned_warehouse_id');
        $this->assertTrue($byWarehouse->has($this->top->id));
        $this->assertTrue($byWarehouse->has($this->bottom->id));

        $this->assertSame($pTop->id, $byWarehouse[$this->top->id]->items->first()->product_id);
        $this->assertSame($pBottom->id, $byWarehouse[$this->bottom->id]->items->first()->product_id);

        // Общий идентификатор оформления
        $this->assertSame(1, $orders->pluck('checkout_uuid')->unique()->count());
        $this->assertTrue($orders->every(fn ($o) => $o->type === OrderType::ORDER));
    }

    #[Test]
    public function товары_одного_склада_остаются_одним_заказом(): void
    {
        $p1 = $this->product([$this->top->id => 5]);
        $p2 = $this->product([$this->top->id => 8]);

        $orders = $this->checkout([[$p1, 2], [$p2, 3]]);

        $this->assertCount(1, $orders);
        $this->assertSame($this->top->id, $orders->first()->assigned_warehouse_id);
        $this->assertCount(2, $orders->first()->items);
    }

    #[Test]
    public function предзаказ_не_получает_зафиксированный_склад(): void
    {
        $preorderWarehouse = Warehouse::factory()->create(['external_id' => 'stack-preorder-uuid']);
        DB::table('region_warehouse')->insert([
            'region_id' => $this->region->id,
            'warehouse_id' => $preorderWarehouse->id,
            'type' => 'preorder',
        ]);

        $product = $this->product([]);
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $preorderWarehouse->id,
            'quantity' => 50,
        ]);

        $orders = $this->checkout([[$product, 5]], 'preorder');

        $this->assertCount(1, $orders);
        $this->assertSame(OrderType::PREORDER, $orders->first()->type);
        $this->assertNull($orders->first()->assigned_warehouse_id);
    }

    #[Test]
    public function регион_без_стопки_оформляется_одним_заказом_без_склада(): void
    {
        $this->region->update(['stock_stack_enabled' => false]);

        $p1 = $this->product([$this->top->id => 5]);
        $p2 = $this->product([$this->bottom->id => 7]);

        $orders = $this->checkout([[$p1, 2], [$p2, 3]]);

        $this->assertCount(1, $orders);
        $this->assertNull($orders->first()->assigned_warehouse_id);
        $this->assertCount(2, $orders->first()->items);
    }
}
