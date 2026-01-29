<?php

namespace Tests\Feature\Listeners;

use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Listeners\PublishUserToErp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PublishUserToErpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_publishes_message_when_user_created(): void
    {
        // We're testing that the listener calls Queue::connection('rabbitmq')->pushRaw()
        // Since this is complex to mock, we'll test the listener logic directly
        $user = User::factory()->make(['id' => 1]);
        $event = new UserCreated($user);

        // Mock the Queue facade
        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_users' 
                    && isset($data['event']) 
                    && $data['event'] === 'UserCreated';
            });

        $listener = new PublishUserToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_publishes_message_when_user_updated(): void
    {
        $user = User::factory()->make(['id' => 1]);
        $event = new UserUpdated($user);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_users' 
                    && isset($data['event']) 
                    && $data['event'] === 'UserUpdated';
            });

        $listener = new PublishUserToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_user(): void
    {
        Queue::shouldReceive('connection')->never();
        Queue::shouldReceive('pushRaw')->never();

        $event = new \stdClass();

        $listener = new PublishUserToErp();
        $listener->handle($event);
        
        $this->assertTrue(true);
    }
}

