<?php

namespace Tests\Feature\Listeners;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Jobs\PublishUserToErpJob;
use App\Listeners\PublishUserToErp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Тесты для PublishUserToErp listener (US-01 v2).
 *
 * partner.created публикуется только когда статус пользователя
 * меняется на «Активен» (UserStatus::ACTIVE).
 */
class PublishUserToErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_partner_created_when_status_changes_to_active(): void
    {
        $user = User::factory()->create([
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
            'email'  => 'test@example.com',
            'name'   => 'Иван',
            'status' => UserStatus::PROCESSING,
        ]);

        // Симулируем изменение статуса на ACTIVE
        $user->status = UserStatus::ACTIVE;
        $user->save();

        // UserUpdated event будет отправлен автоматически при save()
        // Но для теста listener напрямую — имитируем getChanges()
        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            return $job->payload['event'] === 'partner.created'
                && isset($job->payload['uuid'])
                && isset($job->payload['login'])
                && isset($job->payload['name'])
                && isset($job->payload['email'])
                && isset($job->payload['message_id'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_does_not_dispatch_when_status_does_not_change_to_active(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);

        // Изменение несвязанного поля
        $user->name = 'Новое имя';
        $user->save();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_dispatch_on_user_created_event(): void
    {
        $user = User::factory()->make();
        $event = new UserCreated($user);

        $listener = new PublishUserToErp();
        $listener->handle($event);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_user(): void
    {
        $event = new \stdClass();

        $listener = new PublishUserToErp();
        $listener->handle($event);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function partner_created_payload_contains_correct_fields(): void
    {
        $user = User::factory()->create([
            'erp_id'  => 'test-erp-uuid',
            'email'   => 'client@example.com',
            'name'    => 'Иван',
            'surname' => 'Иванов',
            'phone'   => '+7(999)123-45-67',
            'status'  => UserStatus::PROCESSING,
        ]);

        $user->status = UserStatus::ACTIVE;
        $user->save();

        Queue::assertPushed(PublishUserToErpJob::class, function ($job) {
            $p = $job->payload;
            return $p['event'] === 'partner.created'
                && $p['uuid'] === 'test-erp-uuid'
                && $p['login'] === 'client@example.com'
                && $p['email'] === 'client@example.com'
                && str_contains($p['name'], 'Иван');
        });
    }

    #[Test]
    public function it_does_not_dispatch_when_status_changes_from_active_to_blocked(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
        ]);

        Queue::clearResolvedInstances();
        Queue::fake();

        $user->status = UserStatus::BLOCKED;
        $user->save();

        Queue::assertNothingPushed();
    }
}
