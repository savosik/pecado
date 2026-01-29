<?php

namespace Tests\Feature;

use App\Events\OrderDeleted;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class OrderSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_soft_deletes_an_order(): void
    {
        Event::fake([OrderDeleted::class]);

        $order = Order::factory()->create();

        $order->delete();

        // Check if the order is soft deleted
        $this->assertSoftDeleted($order);

        // Check if the event was dispatched
        Event::assertDispatched(OrderDeleted::class, function ($event) use ($order) {
            return $event->order->id === $order->id;
        });
    }

    #[Test]
    public function it_can_restore_a_soft_deleted_order(): void
    {
        $order = Order::factory()->create();
        $order->delete();

        $order->restore();

        $this->assertNotSoftDeleted($order);
    }
}
