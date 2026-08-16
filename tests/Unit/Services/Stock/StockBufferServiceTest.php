<?php

namespace Tests\Unit\Services\Stock;

use App\Models\Product;
use App\Models\ProductStockBuffer;
use App\Services\Stock\StockBufferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Чтение эффективного буфера (карточка buf-01).
 *
 * Инварианты: отсутствие записи = 0, ручная пометка склада побеждает расчёт
 * (включая явный manual_qty = 0 — «не занижать»).
 */
class StockBufferServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StockBufferService
    {
        return new StockBufferService;
    }

    public function test_empty_input_returns_empty_map(): void
    {
        $this->assertSame([], $this->service()->bufferMap([]));
    }

    public function test_missing_record_means_zero(): void
    {
        $product = Product::factory()->create();

        $this->assertSame([$product->id => 0], $this->service()->bufferMap([$product->id]));
        $this->assertSame(0, $this->service()->buffer($product->id));
    }

    public function test_computed_buffer_is_returned(): void
    {
        $product = Product::factory()->create();
        ProductStockBuffer::create(['product_id' => $product->id, 'buffer_qty' => 2]);

        $this->assertSame(2, $this->service()->buffer($product->id));
    }

    public function test_manual_qty_wins_over_computed(): void
    {
        $product = Product::factory()->create();
        ProductStockBuffer::create([
            'product_id' => $product->id,
            'buffer_qty' => 2,
            'manual_qty' => 5,
        ]);

        $this->assertSame(5, $this->service()->buffer($product->id));
    }

    public function test_manual_zero_disables_computed_buffer(): void
    {
        $product = Product::factory()->create();
        ProductStockBuffer::create([
            'product_id' => $product->id,
            'buffer_qty' => 2,
            'manual_qty' => 0,
        ]);

        $this->assertSame(0, $this->service()->buffer($product->id), 'Явный manual_qty = 0 означает «не занижать»');
    }

    public function test_map_covers_all_requested_products(): void
    {
        [$buffered, $plain] = Product::factory()->count(2)->create();
        ProductStockBuffer::create(['product_id' => $buffered->id, 'buffer_qty' => 1]);

        $this->assertSame(
            [$buffered->id => 1, $plain->id => 0],
            $this->service()->bufferMap([$buffered->id, $plain->id]),
        );
    }
}
