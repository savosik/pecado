<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Shipment;
use App\Services\Erp\Handlers\HandleShipmentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleShipmentDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_soft_deletes_shipment(): void
    {
        $shipment = Shipment::factory()->create([
            'uuid' => 'del-ship-test-001',
        ]);

        $handler = new HandleShipmentDeleted;
        $handler->handle([
            'event' => 'shipment.deleted',
            'uuid' => 'del-ship-test-001',
        ]);

        $this->assertSoftDeleted('shipments', ['uuid' => 'del-ship-test-001']);
    }

    #[Test]
    public function it_ignores_unknown_shipment_without_error(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'реализация не найдена');
            });

        $handler = new HandleShipmentDeleted;
        $handler->handle([
            'event' => 'shipment.deleted',
            'uuid' => 'nonexistent-shipment-uuid',
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

        $handler = new HandleShipmentDeleted;
        $handler->handle([
            'event' => 'shipment.deleted',
        ]);
    }
}
