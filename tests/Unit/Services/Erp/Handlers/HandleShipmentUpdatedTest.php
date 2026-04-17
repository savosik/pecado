<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\Shipment;
use App\Services\Erp\Handlers\HandleShipmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleShipmentUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_shipment_status(): void
    {
        $shipment = Shipment::factory()->create([
            'uuid' => 'upd-ship-test-001',
            'status' => 'new',
        ]);

        $handler = new HandleShipmentUpdated;
        $handler->handle([
            'event' => 'shipment.updated',
            'uuid' => 'upd-ship-test-001',
            'status' => 'completed',
        ]);

        $shipment->refresh();
        $this->assertEquals('completed', $shipment->status);
    }

    #[Test]
    public function it_syncs_items_when_provided(): void
    {
        $product = Product::factory()->create(['external_id' => 'upd-ship-prod-001']);
        $shipment = Shipment::factory()->create([
            'uuid' => 'upd-ship-test-002',
            'total_amount' => 0,
        ]);

        $handler = new HandleShipmentUpdated;
        $handler->handle([
            'event' => 'shipment.updated',
            'uuid' => 'upd-ship-test-002',
            'status' => 'completed',
            'items' => [
                ['product_uuid' => 'upd-ship-prod-001', 'quantity' => 3, 'price' => 5000.00],
            ],
        ]);

        $shipment->refresh();
        $this->assertCount(1, $shipment->items);
        $this->assertEquals(15000.00, (float) $shipment->total_amount);
    }

    #[Test]
    public function it_replaces_existing_items(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'upd-ship-old-001']);
        $product2 = Product::factory()->create(['external_id' => 'upd-ship-new-001']);
        $shipment = Shipment::factory()->create(['uuid' => 'upd-ship-test-003']);

        $shipment->items()->create([
            'product_id' => $product1->id,
            'quantity' => 10,
            'price' => 1000,
            'subtotal' => 10000,
        ]);

        $handler = new HandleShipmentUpdated;
        $handler->handle([
            'event' => 'shipment.updated',
            'uuid' => 'upd-ship-test-003',
            'items' => [
                ['product_uuid' => 'upd-ship-new-001', 'quantity' => 2, 'price' => 3000.00],
            ],
        ]);

        $shipment->refresh();
        $this->assertCount(1, $shipment->items);
        $this->assertEquals($product2->id, $shipment->items->first()->product_id);
        $this->assertEquals(6000.00, (float) $shipment->total_amount);
    }

    #[Test]
    public function it_ignores_unknown_shipment_without_error(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'реализация не найдена');
            });

        $handler = new HandleShipmentUpdated;
        $handler->handle([
            'event' => 'shipment.updated',
            'uuid' => 'nonexistent-shipment-uuid',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_does_nothing_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует uuid');
            });

        $handler = new HandleShipmentUpdated;
        $handler->handle([
            'event' => 'shipment.updated',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_updates_date_and_currency_code(): void
    {
        $shipment = Shipment::factory()->create([
            'uuid' => 'upd-ship-test-004',
            'date' => '2026-01-01',
            'currency_code' => 'RUB',
        ]);

        $handler = new HandleShipmentUpdated;
        $handler->handle([
            'event' => 'shipment.updated',
            'uuid' => 'upd-ship-test-004',
            'date' => '2026-03-01',
            'currency_code' => 'KZT',
        ]);

        $shipment->refresh();
        $this->assertEquals('2026-03-01', $shipment->date->format('Y-m-d'));
        $this->assertEquals('KZT', $shipment->currency_code);
    }
}
