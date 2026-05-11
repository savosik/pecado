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
    public function soft_deletes_existing_order_and_sets_status_to_closed(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'order-delete-unit-001',
            'status' => \App\Enums\OrderStatus::READY_FOR_PROVISION,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'event' => 'order.deleted',
            'uuid' => 'order-delete-unit-001',
        ]);

        $fresh = Order::withTrashed()->where('uuid', 'order-delete-unit-001')->first();
        $this->assertEquals('closed', $fresh->status->value);
        $this->assertNotNull($fresh->deleted_at);
    }

    #[Test]
    public function is_idempotent_for_already_soft_deleted_order(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'order-delete-unit-002',
            'status' => \App\Enums\OrderStatus::CLOSED,
        ]);
        $order->delete();
        $deletedAtBefore = $order->fresh()->deleted_at;

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'event' => 'order.deleted',
            'uuid' => 'order-delete-unit-002',
        ]);

        $fresh = Order::withTrashed()->where('uuid', 'order-delete-unit-002')->first();
        $this->assertEquals('closed', $fresh->status->value);
        $this->assertEquals($deletedAtBefore->toDateTimeString(), $fresh->deleted_at->toDateTimeString());
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
