<?php

namespace Tests\Feature\Listeners;

use App\Events\OrderCreated;
use App\Events\OrderUpdated;
use App\Events\OrderDeleted;
use App\Listeners\PublishOrderToErp;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PublishOrderToErpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_publishes_message_when_order_created(): void
    {
        $order = Order::factory()->create();
        $event = new OrderCreated($order);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_orders' 
                    && isset($data['event']) 
                    && $data['event'] === 'OrderCreated'
                    && isset($data['order']['uuid']);
            });

        $listener = new PublishOrderToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_publishes_message_when_order_updated(): void
    {
        $order = Order::factory()->create();
        $event = new OrderUpdated($order);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_orders' 
                    && isset($data['event']) 
                    && $data['event'] === 'OrderUpdated'
                    && isset($data['order']['uuid']);
            });

        $listener = new PublishOrderToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_publishes_message_when_order_deleted(): void
    {
        $order = Order::factory()->create();
        $event = new OrderDeleted($order);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_orders' 
                    && isset($data['event']) 
                    && $data['event'] === 'OrderDeleted'
                    && isset($data['order']['uuid']);
            });

        $listener = new PublishOrderToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_order(): void
    {
        Queue::shouldReceive('connection')->never();
        Queue::shouldReceive('pushRaw')->never();

        $event = new \stdClass();

        $listener = new PublishOrderToErp();
        $listener->handle($event);
        
        $this->assertTrue(true);
    }
}
