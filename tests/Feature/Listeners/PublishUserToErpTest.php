<?php

namespace Tests\Feature\Listeners;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Jobs\PublishUserToErpJob;
use App\Listeners\PublishUserToErp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublishUserToErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_partner_created_on_user_created_event(): void
    {
        $user = User::factory()->create([
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
            'email' => 'test@example.com',
            'name' => 'Иван',
            'status' => UserStatus::PROCESSING,
        ]);

        Queue::fake();

        $listener = new PublishUserToErp;
        $listener->handle(new UserCreated($user));

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            return $job->payload['event'] === 'partner.created'
                && isset($job->payload['uuid'])
                && isset($job->payload['login'])
                && isset($job->payload['name'])
                && isset($job->payload['email'])
                && isset($job->payload['message_id'])
                && isset($job->payload['timestamp'])
                && array_key_exists('is_active', $job->payload)
                && array_key_exists('comment', $job->payload);
        });
    }

    #[Test]
    public function it_does_not_dispatch_on_user_created_without_name(): void
    {
        $user = User::factory()->create(['name' => null, 'status' => UserStatus::PROCESSING]);

        Queue::fake();

        $listener = new PublishUserToErp;
        $listener->handle(new UserCreated($user));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_dispatches_partner_created_when_name_set_for_first_time_on_update(): void
    {
        $user = User::factory()->create(['name' => null, 'status' => UserStatus::PROCESSING]);

        Queue::fake();

        $user->name = 'Иван Иванов';
        $user->save();

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            return $job->payload['event'] === 'partner.created'
                && $job->payload['name'] === 'Иван Иванов';
        });
    }

    #[Test]
    public function it_does_not_dispatch_on_user_updated_when_name_already_set(): void
    {
        $user = User::factory()->create([
            'name' => 'Уже есть имя',
            'status' => UserStatus::PROCESSING,
        ]);

        Queue::fake();

        $user->status = UserStatus::ACTIVE;
        $user->save();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_dispatch_on_user_updated_when_name_changes_but_was_not_null(): void
    {
        $user = User::factory()->create(['name' => 'Старое имя', 'status' => UserStatus::PROCESSING]);

        Queue::fake();

        $user->name = 'Новое имя';
        $user->save();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_user(): void
    {
        $listener = new PublishUserToErp;
        $listener->handle(new \stdClass);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function partner_created_payload_contains_correct_fields(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'test-erp-uuid',
            'email' => 'client@example.com',
            'name' => 'Иванов Иван',
            'phone' => '+7(999)123-45-67',
            'status' => UserStatus::ACTIVE,
            'comment' => 'VIP клиент',
        ]);

        Queue::fake();

        $listener = new PublishUserToErp;
        $listener->handle(new UserCreated($user));

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) use ($user) {
            $p = $job->payload;

            return $p['event'] === 'partner.created'
                && $p['uuid'] === 'test-erp-uuid'
                && $p['login'] === 'client@example.com'
                && $p['email'] === 'client@example.com'
                && str_contains($p['name'], 'Иван')
                && $p['is_active'] === true
                && str_contains($p['comment'], $user->view_token);
        });
    }

    #[Test]
    public function is_active_is_false_when_user_status_is_processing(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::PROCESSING,
        ]);

        Queue::fake();

        $listener = new PublishUserToErp;
        $listener->handle(new UserCreated($user));

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            return $job->payload['is_active'] === false;
        });
    }
}
