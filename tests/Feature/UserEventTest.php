<?php

namespace Tests\Feature;

use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_created_event_is_dispatched_when_user_is_created(): void
    {
        Event::fake([UserCreated::class]);

        $user = User::factory()->create();

        Event::assertDispatched(UserCreated::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    #[Test]
    public function user_updated_event_is_dispatched_when_user_is_updated(): void
    {
        $user = User::factory()->create();

        Event::fake([UserUpdated::class]);

        $user->update(['name' => 'Updated Name']);

        Event::assertDispatched(UserUpdated::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    #[Test]
    public function user_created_event_contains_user_model(): void
    {
        $user = User::factory()->make();
        $event = new UserCreated($user);

        $this->assertSame($user, $event->user);
    }

    #[Test]
    public function user_updated_event_contains_user_model(): void
    {
        $user = User::factory()->make();
        $event = new UserUpdated($user);

        $this->assertSame($user, $event->user);
    }
}
