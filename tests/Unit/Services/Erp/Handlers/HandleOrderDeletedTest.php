<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Order;
use App\Services\Erp\Handlers\HandleOrderDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleOrderDeletedTest extends TestCase
{
    use RefreshDatabase;

    private HandleOrderDeleted $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(HandleOrderDeleted::class);
    }

    #[Test]
    public function sets_deleted_status_for_existing_order(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'order-delete-unit-001',
            'status' => 'confirmed',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'event' => 'order.deleted',
            'uuid' => 'order-delete-unit-001',
        ]);

        $this->assertEquals('deleted', $order->fresh()->status->value);
        $this->assertNull($order->fresh()->deleted_at);
    }

    #[Test]
    public function skips_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')->once();

        $this->handler->handle([
            'event' => 'order.deleted',
        ]);

        $this->assertDatabaseCount('orders', 0);
    }
}
