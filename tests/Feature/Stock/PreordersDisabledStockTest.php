<?php

namespace Tests\Feature\Stock;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Флаг «предлагать предзаказ» выключен — предзаказных складов для клиента нет.
 *
 * Одна точка (StockService::regionWarehouseIds) гасит предзаказ на всех
 * поверхностях: карта остатков, подзапросы каталога, перелив корзины.
 */
class PreordersDisabledStockTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $primary = Warehouse::factory()->create(['name' => 'Основной']);
        $preorder = Warehouse::factory()->create(['name' => 'Предзаказный']);
        $this->region = Region::factory()->create(['name' => 'Тестовый регион']);

        foreach ([[$primary->id, 'primary'], [$preorder->id, 'preorder']] as [$warehouseId, $type]) {
            DB::table('region_warehouse')->insert([
                'region_id' => $this->region->id,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->product = Product::factory()->create(['base_price' => 1000]);
        DB::table('product_warehouse')->insert([
            ['product_id' => $this->product->id, 'warehouse_id' => $primary->id, 'quantity' => 2],
            ['product_id' => $this->product->id, 'warehouse_id' => $preorder->id, 'quantity' => 5],
        ]);
    }

    private function client(bool $preordersEnabled): User
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);
        $user->forceFill(['preorders_enabled' => $preordersEnabled])->save();

        return $user->fresh();
    }

    #[Test]
    public function stock_map_hides_preorder_warehouses_for_opted_out_client(): void
    {
        $stock = app(StockServiceInterface::class);

        $this->assertSame(['available' => 2, 'preorder' => 5], $stock->getStock($this->product, $this->client(true)));
        $this->assertSame(['available' => 2, 'preorder' => 0], $stock->getStock($this->product, $this->client(false)));
    }

    #[Test]
    public function memoized_region_warehouses_do_not_leak_between_clients_in_one_request(): void
    {
        // Мемо складов — по региону; фильтр по флагу применяется после него,
        // иначе первый же клиент без предзаказов «выключил» бы их всему региону.
        $stock = app(StockServiceInterface::class);
        $optedOut = $this->client(false);
        $regular = $this->client(true);

        $this->assertSame(0, $stock->getStock($this->product, $optedOut)['preorder']);
        $this->assertSame(5, $stock->getStock($this->product, $regular)['preorder']);
        $this->assertSame(0, $stock->getStock($this->product, $optedOut)['preorder']);
    }

    #[Test]
    public function catalog_subselects_report_zero_preorder_stock(): void
    {
        $query = Product::query()->select('products.*')->whereKey($this->product->id);
        app(\App\Services\Stock\StockService::class)->applyStockSubselects($query, $this->client(false));

        $row = $query->first();

        $this->assertSame(2, (int) $row->primary_stock);
        $this->assertSame(0, (int) $row->preorder_stock);
    }

    #[Test]
    public function cart_does_not_spill_into_preorder_for_opted_out_client(): void
    {
        $user = $this->client(false);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $result = app(CartServiceInterface::class)->setProductQuantity($user, $cart, $this->product, 10);

        $this->assertSame(2, $result['instock']);
        $this->assertSame(0, $result['preorder']);
        $this->assertSame(2, $result['clamped']);
        $this->assertSame(2, $result['max_total']);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id, 'item_type' => 'preorder']);
    }

    #[Test]
    public function cart_still_spills_for_client_with_preorders_enabled(): void
    {
        $user = $this->client(true);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $result = app(CartServiceInterface::class)->setProductQuantity($user, $cart, $this->product, 10);

        $this->assertSame(2, $result['instock']);
        $this->assertSame(5, $result['preorder']);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'item_type' => 'preorder', 'quantity' => 5]);
    }

    #[Test]
    public function checkout_page_drops_stale_preorder_rows_for_opted_out_client(): void
    {
        // Строки легли в корзину, пока предзаказ был включён; выключили в CRM.
        $user = $this->client(false);
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => 2, 'item_type' => 'instock']);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => 3, 'item_type' => 'preorder']);

        $this->actingAs($user)->get('/checkout')->assertOk();

        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id, 'item_type' => 'preorder']);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'item_type' => 'instock', 'quantity' => 2]);
    }
}
