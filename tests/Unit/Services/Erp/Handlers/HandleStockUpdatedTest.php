<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Erp\Handlers\HandleStockUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleStockUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_stock_quantity_for_existing_pivot(): void
    {
        $product = Product::factory()->create([
            'external_id' => '550e8400-e29b-41d4-a716-stock-001',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'w1a2b3c4-stock-001',
        ]);

        // Создаём начальную связь
        $product->warehouses()->attach($warehouse->id, ['quantity' => 10]);

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-stock-001',
            'warehouse_uuid' => 'w1a2b3c4-stock-001',
            'quantity' => 42,
        ]);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 42,
        ]);
    }

    #[Test]
    public function it_creates_pivot_record_when_not_exists(): void
    {
        $product = Product::factory()->create([
            'external_id' => '550e8400-e29b-41d4-a716-stock-002',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'w1a2b3c4-stock-002',
        ]);

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-stock-002',
            'warehouse_uuid' => 'w1a2b3c4-stock-002',
            'quantity' => 25,
        ]);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 25,
        ]);
    }

    #[Test]
    public function it_ignores_unknown_product_without_error(): void
    {
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'w1a2b3c4-stock-003',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'товар не найден по UUID');
            });

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => 'nonexistent-product-uuid',
            'warehouse_uuid' => 'w1a2b3c4-stock-003',
            'quantity' => 10,
        ]);

        $this->assertDatabaseCount('product_warehouse', 0);
    }

    #[Test]
    public function it_updates_stock_for_hidden_product(): void
    {
        // v13.2: HiddenScope не должен прятать товар от stock.updated.
        $product = Product::factory()->create([
            'external_id' => 'hidden-stock-uuid',
            'hidden' => true,
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'w1a2b3c4-hidden',
        ]);

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => 'hidden-stock-uuid',
            'warehouse_uuid' => 'w1a2b3c4-hidden',
            'quantity' => 7,
        ]);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 7,
        ]);
    }

    #[Test]
    public function it_ignores_unknown_warehouse_without_error(): void
    {
        $product = Product::factory()->create([
            'external_id' => '550e8400-e29b-41d4-a716-stock-004',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'склад не найден по UUID');
            });

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-stock-004',
            'warehouse_uuid' => 'nonexistent-warehouse-uuid',
            'quantity' => 10,
        ]);

        $this->assertDatabaseCount('product_warehouse', 0);
    }

    #[Test]
    public function it_does_nothing_when_product_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует product_uuid, warehouse_uuid или quantity');
            });

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'warehouse_uuid' => 'w1a2b3c4-stock-005',
            'quantity' => 10,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_warehouse_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует product_uuid, warehouse_uuid или quantity');
            });

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-stock-006',
            'quantity' => 10,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_quantity_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует product_uuid, warehouse_uuid или quantity');
            });

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-stock-007',
            'warehouse_uuid' => 'w1a2b3c4-stock-007',
        ]);
    }

    #[Test]
    public function it_updates_quantity_to_zero(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'zero-stock-uuid',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'zero-stock-wh-uuid',
        ]);

        $product->warehouses()->attach($warehouse->id, ['quantity' => 50]);

        $handler = app(HandleStockUpdated::class);
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => 'zero-stock-uuid',
            'warehouse_uuid' => 'zero-stock-wh-uuid',
            'quantity' => 0,
        ]);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 0,
        ]);
    }

    #[Test]
    public function it_overwrites_existing_quantity(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'overwrite-stock-uuid',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'overwrite-stock-wh-uuid',
        ]);

        $handler = app(HandleStockUpdated::class);

        // Первое обновление
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => 'overwrite-stock-uuid',
            'warehouse_uuid' => 'overwrite-stock-wh-uuid',
            'quantity' => 100,
        ]);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
        ]);

        // Второе обновление
        $handler->handle([
            'event' => 'stock.updated',
            'product_uuid' => 'overwrite-stock-uuid',
            'warehouse_uuid' => 'overwrite-stock-wh-uuid',
            'quantity' => 35,
        ]);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 35,
        ]);
    }
}
