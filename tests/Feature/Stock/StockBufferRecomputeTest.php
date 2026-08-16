<?php

namespace Tests\Feature\Stock;

use App\Models\Attribute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\ProductStockBuffer;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Пересчёт страхового буфера по сигналам риска (карточка buf-01).
 *
 * Ключевые инварианты: буфер только по рисковым SKU, clamp по остатку
 * (треть склада лежит по 1–2 шт — их прятать нельзя), ручные пометки
 * переживают пересчёт, повторный запуск без изменений не пишет ни строки.
 */
class StockBufferRecomputeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['name' => 'Основной']);
        $region = Region::factory()->create(['name' => 'Регион по умолчанию']);

        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function productWithStock(int $quantity): Product
    {
        $product = Product::factory()->create();
        $product->warehouses()->attach($this->warehouse->id, ['quantity' => $quantity]);

        return $product;
    }

    /**
     * Отменённая на сборке строка заказа — основной сигнал риска.
     */
    private function cancelledLine(Product $product, ?string $erpCreatedAt = null): void
    {
        $order = Order::factory()->create([
            'erp_created_at' => $erpCreatedAt ?? now()->subDays(10),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Позиция с отменой',
            'price' => 100,
            'base_price' => 100,
            'discount_percent' => 0,
            'final_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'cancelled' => true,
        ]);
    }

    public function test_repeated_cancellations_create_buffer(): void
    {
        $product = $this->productWithStock(30);
        $this->cancelledLine($product);
        $this->cancelledLine($product);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $buffer = ProductStockBuffer::where('product_id', $product->id)->sole();
        $this->assertSame(1, $buffer->buffer_qty, 'Один сигнал (отмены) → буфер 1 шт');
        $this->assertSame(['cancellations' => 2], $buffer->reasons);
        $this->assertNotNull($buffer->computed_at);
    }

    public function test_single_cancellation_is_not_a_signal(): void
    {
        $product = $this->productWithStock(30);
        $this->cancelledLine($product);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    public function test_cancellations_outside_window_are_ignored(): void
    {
        $product = $this->productWithStock(30);
        $this->cancelledLine($product, now()->subDays(120)->toDateTimeString());
        $this->cancelledLine($product, now()->subDays(130)->toDateTimeString());

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    public function test_two_signals_give_buffer_of_two(): void
    {
        $product = $this->productWithStock(30);
        $this->cancelledLine($product);
        $this->cancelledLine($product);
        ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $buffer = ProductStockBuffer::where('product_id', $product->id)->sole();
        $this->assertSame(2, $buffer->buffer_qty);
        $this->assertSame(['cancellations' => 2, 'defect_batches' => 1], $buffer->reasons);
    }

    public function test_defect_batch_closed_long_ago_is_ignored(): void
    {
        $product = $this->productWithStock(30);
        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'closed_at' => now()->subDays(200),
        ]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    public function test_near_shelf_life_is_a_signal(): void
    {
        $product = $this->productWithStock(30);

        $attribute = Attribute::create([
            'name' => 'Срок годности (годен до)',
            'slug' => config('catalog.shelf_life_attribute_slug'),
            'type' => 'date-time',
            'is_active' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $attribute->id,
            'datetime_value' => now()->addMonths(2),
        ]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $buffer = ProductStockBuffer::where('product_id', $product->id)->sole();
        $this->assertSame(1, $buffer->buffer_qty);
        $this->assertSame(['shelf_life' => true], $buffer->reasons);
    }

    public function test_shelf_life_without_stock_is_not_a_signal(): void
    {
        // Распроданный товар с истёкшей датой — не рисковая полка, а шум:
        // на копии прода таких было 943 из 957.
        $product = $this->productWithStock(0);

        $attribute = Attribute::create([
            'name' => 'Срок годности (годен до)',
            'slug' => config('catalog.shelf_life_attribute_slug'),
            'type' => 'date-time',
            'is_active' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $attribute->id,
            'datetime_value' => now()->subMonths(2),
        ]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    public function test_far_shelf_life_is_not_a_signal(): void
    {
        $product = $this->productWithStock(30);

        $attribute = Attribute::create([
            'name' => 'Срок годности (годен до)',
            'slug' => config('catalog.shelf_life_attribute_slug'),
            'type' => 'date-time',
            'is_active' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $attribute->id,
            'datetime_value' => now()->addYears(2),
        ]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    public function test_tiny_stock_clamps_buffer_to_zero_but_keeps_reasons(): void
    {
        // Треть позиций склада лежит по 1–2 шт: рисковый SKU с таким остатком
        // получает буфер 0 (прятать нечего), но раскладка сигналов остаётся
        // для WMS-консоли.
        $product = $this->productWithStock(2);
        $this->cancelledLine($product);
        $this->cancelledLine($product);
        ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $buffer = ProductStockBuffer::where('product_id', $product->id)->sole();
        $this->assertSame(0, $buffer->buffer_qty, 'Остаток ниже min_stock → буфер 0 при любых сигналах');
        $this->assertNotNull($buffer->reasons);
    }

    public function test_ten_percent_cap_limits_buffer(): void
    {
        // Остаток 5: cap = min(2, ceil(0.5)) = 1, хотя сигналов на 2.
        $product = $this->productWithStock(5);
        $this->cancelledLine($product);
        $this->cancelledLine($product);
        ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertSame(1, ProductStockBuffer::where('product_id', $product->id)->sole()->buffer_qty);
    }

    public function test_zero_stock_gives_zero_buffer(): void
    {
        $product = $this->productWithStock(0);
        $this->cancelledLine($product);
        $this->cancelledLine($product);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertSame(0, ProductStockBuffer::where('product_id', $product->id)->sole()->buffer_qty);
    }

    public function test_manual_qty_survives_recompute(): void
    {
        $product = $this->productWithStock(30);
        ProductStockBuffer::create([
            'product_id' => $product->id,
            'buffer_qty' => 2,
            'manual_qty' => 3,
            'reasons' => ['cancellations' => 2],
        ]);

        // Сигналы исчезли — расчётный буфер обнуляется, ручной живёт.
        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $buffer = ProductStockBuffer::where('product_id', $product->id)->sole();
        $this->assertSame(0, $buffer->buffer_qty);
        $this->assertSame(3, $buffer->manual_qty);
        $this->assertNull($buffer->reasons);
    }

    public function test_lost_signals_delete_computed_row(): void
    {
        $product = $this->productWithStock(30);
        ProductStockBuffer::create([
            'product_id' => $product->id,
            'buffer_qty' => 2,
            'reasons' => ['cancellations' => 2],
        ]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    public function test_repeated_run_writes_nothing(): void
    {
        $product = $this->productWithStock(30);
        $this->cancelledLine($product);
        $this->cancelledLine($product);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $before = ProductStockBuffer::where('product_id', $product->id)->sole();

        $this->travel(1)->hours();
        $this->artisan('stock:buffers:recompute')
            ->expectsOutputToContain('изменилось 0 SKU')
            ->assertSuccessful();

        $after = ProductStockBuffer::where('product_id', $product->id)->sole();
        $this->assertTrue(
            $before->updated_at->equalTo($after->updated_at) && $before->computed_at->equalTo($after->computed_at),
            'Пересчёт без изменений не должен трогать ни одной строки',
        );
    }
}
