<?php

namespace Tests\Feature\Listeners;

use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Jobs\PublishUserToErpJob;
use App\Listeners\PublishUserToErp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PublishUserToErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_job_when_user_created(): void
    {
        $user = User::factory()->make(['id' => 1]);
        $event = new UserCreated($user);

        $listener = new PublishUserToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            return $job->payload['event'] === 'UserCreated'
                && isset($job->payload['user'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_dispatches_job_when_user_updated(): void
    {
        $user = User::factory()->make(['id' => 1]);
        $event = new UserUpdated($user);

        $listener = new PublishUserToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            return $job->payload['event'] === 'UserUpdated'
                && isset($job->payload['user'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_user(): void
    {
        $event = new \stdClass();

        $listener = new PublishUserToErp();
        $listener->handle($event);

        Queue::assertNothingPushed();
    }
}
