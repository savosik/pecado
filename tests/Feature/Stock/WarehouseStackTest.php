<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Режим стопки складов в StockService: строгое замещение остатков
 * по приоритету вместо суммирования; карта выигравших складов;
 * страховой буфер поверх победителя; подзапросы сортировки каталога.
 */
class WarehouseStackTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private User $user;

    private Warehouse $top;

    private Warehouse $bottom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create(['stock_stack_enabled' => true]);
        $this->top = Warehouse::factory()->create(['name' => 'Верхний']);
        $this->bottom = Warehouse::factory()->create(['name' => 'Нижний']);

        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $this->top->id, 'type' => 'primary', 'priority' => 1],
            ['region_id' => $this->region->id, 'warehouse_id' => $this->bottom->id, 'type' => 'primary', 'priority' => 2],
        ]);

        $this->user = User::factory()->create(['region_id' => $this->region->id]);
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

    #[Test]
    public function остаток_верхнего_склада_замещает_нижний_а_не_суммируется(): void
    {
        $product = $this->product([$this->top->id => 5, $this->bottom->id => 100]);

        $stock = (new StockService)->getStock($product, $this->user);

        $this->assertSame(5, $stock['available']);
    }

    #[Test]
    public function без_наличия_наверху_действует_остаток_нижнего_склада(): void
    {
        $product = $this->product([$this->top->id => 0, $this->bottom->id => 100]);

        $stock = (new StockService)->getStock($product, $this->user);

        $this->assertSame(100, $stock['available']);
    }

    #[Test]
    public function карта_победителей_отдаёт_склад_чей_остаток_действует(): void
    {
        $pTop = $this->product([$this->top->id => 5, $this->bottom->id => 100]);
        $pBottom = $this->product([$this->bottom->id => 7]);
        $pNone = $this->product([]);

        $map = (new StockService)->getWinningWarehouseMap(
            [$pTop->id, $pBottom->id, $pNone->id],
            $this->user,
        );

        $this->assertSame($this->top->id, $map[$pTop->id]);
        $this->assertSame($this->bottom->id, $map[$pBottom->id]);
        $this->assertNull($map[$pNone->id]);
    }

    #[Test]
    public function для_региона_без_стопки_карта_победителей_пустая_и_остатки_суммируются(): void
    {
        $this->region->update(['stock_stack_enabled' => false]);

        $product = $this->product([$this->top->id => 5, $this->bottom->id => 100]);
        $service = new StockService;

        $this->assertSame(105, $service->getStock($product, $this->user)['available']);
        $this->assertNull($service->getWinningWarehouseMap([$product->id], $this->user)[$product->id]);
    }

    #[Test]
    public function preorder_остатки_не_затрагиваются_режимом_стопки(): void
    {
        $preorderWarehouse = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            'region_id' => $this->region->id,
            'warehouse_id' => $preorderWarehouse->id,
            'type' => 'preorder',
        ]);

        $product = $this->product([$this->top->id => 5]);
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $preorderWarehouse->id,
            'quantity' => 50,
        ]);

        $stock = (new StockService)->getStock($product, $this->user);

        $this->assertSame(5, $stock['available']);
        $this->assertSame(50, $stock['preorder']);
    }

    #[Test]
    public function буфер_вычитается_из_остатка_победителя_а_не_меняет_победителя(): void
    {
        config()->set('stock_buffer.enabled', true);
        DB::table('users')->where('id', $this->user->id)->update(['stock_buffer_enabled' => true]);

        $product = $this->product([$this->top->id => 5, $this->bottom->id => 100]);

        DB::table('product_stock_buffers')->insert([
            'product_id' => $product->id,
            'buffer_qty' => 10,
            'manual_qty' => null,
            'computed_at' => now(),
        ]);

        $service = new StockService;
        $stock = $service->getStock($product, $this->user->fresh());

        // Победитель — верхний склад (5 шт по сырому остатку); буфер 10 съедает
        // его до нуля. Проваливания на нижний склад нет — заложенное следствие
        // строгого замещения.
        $this->assertSame(0, $stock['available']);
        $this->assertSame(
            $this->top->id,
            $service->getWinningWarehouseMap([$product->id], $this->user->fresh())[$product->id],
        );
    }

    #[Test]
    public function подзапрос_сортировки_каталога_отдаёт_остаток_победителя(): void
    {
        $pTop = $this->product([$this->top->id => 5, $this->bottom->id => 100]);
        $pBottom = $this->product([$this->top->id => 0, $this->bottom->id => 7]);

        $query = Product::query()->select('products.*');
        (new StockService)->applyStockSubselects($query, $this->user);

        $rows = $query->get()->keyBy('id');

        $this->assertSame(5, (int) $rows[$pTop->id]->primary_stock);
        $this->assertSame(7, (int) $rows[$pBottom->id]->primary_stock);
    }

    #[Test]
    public function подзапрос_сортировки_для_региона_без_стопки_суммирует_как_раньше(): void
    {
        $this->region->update(['stock_stack_enabled' => false]);

        $product = $this->product([$this->top->id => 5, $this->bottom->id => 100]);

        $query = Product::query()->select('products.*');
        (new StockService)->applyStockSubselects($query, $this->user);

        $this->assertSame(105, (int) $query->get()->keyBy('id')[$product->id]->primary_stock);
    }
}
