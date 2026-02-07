<?php

namespace Tests\Feature\Listeners;

use App\Events\OrderCreated;
use App\Events\OrderUpdated;
use App\Events\OrderDeleted;
use App\Jobs\PublishOrderToErpJob;
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

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_job_when_order_created(): void
    {
        $order = Order::factory()->create();
        $event = new OrderCreated($order);

        $listener = new PublishOrderToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['event'] === 'OrderCreated'
                && isset($job->payload['order'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_dispatches_job_when_order_updated(): void
    {
        $order = Order::factory()->create();
        $event = new OrderUpdated($order);

        $listener = new PublishOrderToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['event'] === 'OrderUpdated'
                && isset($job->payload['order'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_dispatches_job_when_order_deleted(): void
    {
        $order = Order::factory()->create();
        $event = new OrderDeleted($order);

        $listener = new PublishOrderToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['event'] === 'OrderDeleted'
                && isset($job->payload['order'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_order(): void
    {
        $event = new \stdClass();

        $listener = new PublishOrderToErp();
        $listener->handle($event);

        Queue::assertNothingPushed();
    }
}
