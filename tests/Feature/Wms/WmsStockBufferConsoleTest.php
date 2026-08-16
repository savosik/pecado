<?php

namespace Tests\Feature\Wms;

use App\Models\Product;
use App\Models\ProductStockBuffer;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WMS-консоль «Страховой запас» (карточка buf-06).
 *
 * Ручные пометки «придержи N шт» ставит склад; автор и время фиксируются.
 * Расчётный буфер руками не редактируется.
 */
class WmsStockBufferConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create();
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::factory()->create(['sku' => 'BUF-001']);
        $this->product->warehouses()->attach($warehouse->id, ['quantity' => 10]);
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create();
        $user->assignRole('storekeeper');

        return $user;
    }

    #[Test]
    public function storekeeper_sees_risky_sku_with_reasons(): void
    {
        ProductStockBuffer::create([
            'product_id' => $this->product->id,
            'buffer_qty' => 2,
            'reasons' => ['cancellations' => 3],
        ]);

        $this->actingAs($this->storekeeper())
            ->get(route('wms.stock-buffers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/StockBuffers/Index')
                ->where('rows.0.product_id', $this->product->id)
                ->where('rows.0.stock', 10)
                ->where('rows.0.effective_qty', 2)
                ->where('rows.0.hidden', 2)
                ->where('rows.0.reasons.0', '3 отмен за 90 дн')
                ->where('summary.hidden_units', 2));
    }

    #[Test]
    public function user_without_wms_permission_is_redirected_away(): void
    {
        $client = User::factory()->create();

        // Мидлварь 'wms' уводит посторонних на главную (как во всём разделе).
        $this->actingAs($client)
            ->get(route('wms.stock-buffers.index'))
            ->assertRedirect('/');
    }

    #[Test]
    public function manual_mark_is_stored_with_author(): void
    {
        $keeper = $this->storekeeper();

        $this->actingAs($keeper)
            ->post(route('wms.stock-buffers.manual.store'), [
                'product_id' => $this->product->id,
                'manual_qty' => 2,
            ])
            ->assertRedirect();

        $buffer = ProductStockBuffer::where('product_id', $this->product->id)->sole();
        $this->assertSame(2, $buffer->manual_qty);
        $this->assertSame($keeper->id, $buffer->manual_set_by);
        $this->assertNotNull($buffer->manual_set_at);
    }

    #[Test]
    public function manual_mark_updates_existing_computed_row(): void
    {
        ProductStockBuffer::create([
            'product_id' => $this->product->id,
            'buffer_qty' => 1,
            'reasons' => ['defect_batches' => 1],
        ]);

        $this->actingAs($this->storekeeper())
            ->post(route('wms.stock-buffers.manual.store'), [
                'product_id' => $this->product->id,
                'manual_qty' => 3,
            ])
            ->assertRedirect();

        $buffer = ProductStockBuffer::where('product_id', $this->product->id)->sole();
        $this->assertSame(3, $buffer->manual_qty);
        $this->assertSame(1, $buffer->buffer_qty, 'Расчётный буфер руками не трогается');
    }

    #[Test]
    public function clearing_manual_on_computed_row_keeps_computed_buffer(): void
    {
        $keeper = $this->storekeeper();
        $buffer = ProductStockBuffer::create([
            'product_id' => $this->product->id,
            'buffer_qty' => 1,
            'reasons' => ['cancellations' => 2],
            'manual_qty' => 5,
            'manual_set_by' => $keeper->id,
            'manual_set_at' => now(),
        ]);

        $this->actingAs($keeper)
            ->delete(route('wms.stock-buffers.manual.destroy', $buffer))
            ->assertRedirect();

        $buffer->refresh();
        $this->assertNull($buffer->manual_qty);
        $this->assertNull($buffer->manual_set_by);
        $this->assertSame(1, $buffer->buffer_qty, 'Снятие пометки возвращает расчётное значение');
    }

    #[Test]
    public function clearing_manual_on_manual_only_row_deletes_it(): void
    {
        $keeper = $this->storekeeper();
        $buffer = ProductStockBuffer::create([
            'product_id' => $this->product->id,
            'buffer_qty' => 0,
            'manual_qty' => 2,
            'manual_set_by' => $keeper->id,
            'manual_set_at' => now(),
        ]);

        $this->actingAs($keeper)
            ->delete(route('wms.stock-buffers.manual.destroy', $buffer))
            ->assertRedirect();

        $this->assertDatabaseCount('product_stock_buffers', 0);
    }

    #[Test]
    public function manual_mark_and_author_survive_nightly_recompute(): void
    {
        $keeper = $this->storekeeper();

        $this->actingAs($keeper)
            ->post(route('wms.stock-buffers.manual.store'), [
                'product_id' => $this->product->id,
                'manual_qty' => 2,
            ]);

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $buffer = ProductStockBuffer::where('product_id', $this->product->id)->sole();
        $this->assertSame(2, $buffer->manual_qty);
        $this->assertSame($keeper->id, $buffer->manual_set_by);
    }

    #[Test]
    public function cancellation_metric_counts_only_segment_clients(): void
    {
        $segment = User::factory()->create();
        $segment->forceFill(['stock_buffer_enabled' => true])->save();
        $outsider = User::factory()->create();

        $withCancellation = \App\Models\Order::factory()->create([
            'user_id' => $segment->id,
            'erp_created_at' => now()->startOfMonth()->addHours(10),
        ]);
        \App\Models\OrderItem::create([
            'order_id' => $withCancellation->id,
            'product_id' => $this->product->id,
            'name' => 'Отменённая строка',
            'price' => 100, 'base_price' => 100, 'discount_percent' => 0,
            'final_price' => 100, 'quantity' => 1, 'subtotal' => 100,
            'cancelled' => true,
        ]);
        \App\Models\Order::factory()->create([
            'user_id' => $segment->id,
            'erp_created_at' => now()->startOfMonth()->addHours(11),
        ]);
        // Заказ клиента вне сегмента в метрику не входит.
        \App\Models\Order::factory()->create([
            'user_id' => $outsider->id,
            'erp_created_at' => now()->startOfMonth()->addHours(12),
        ]);

        $this->actingAs($this->storekeeper())
            ->get(route('wms.stock-buffers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cancellations.0.orders', 2)
                ->where('cancellations.0.with_cancellations', 1)
                ->where('cancellations.0.pct', 50));
    }

    #[Test]
    public function product_search_returns_stock_and_existing_marks(): void
    {
        ProductStockBuffer::create([
            'product_id' => $this->product->id,
            'buffer_qty' => 1,
            'manual_qty' => 4,
        ]);

        $response = $this->actingAs($this->storekeeper())
            ->getJson(route('wms.stock-buffers.search-products', ['query' => $this->product->sku]));

        $row = collect($response->assertOk()->json())->firstWhere('id', $this->product->id);
        $this->assertSame(10, $row['stock']);
        $this->assertSame(4, $row['manual_qty']);
    }
}
