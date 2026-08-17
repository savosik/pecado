<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\CrmTaskReminderLog;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Crm\TaskPushNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-09: браузерные push-уведомления по задачам.
 */
class TaskPushTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function subscribe(User $user): void
    {
        $user->updatePushSubscription(
            'https://push.example/'.$user->id,
            'p256dh-key',
            'auth-token',
            'aes128gcm',
        );
    }

    #[Test]
    public function browser_subscription_is_stored_and_removed(): void
    {
        config(['crm.push.enabled' => true]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.push.store'), [
                'endpoint' => 'https://push.example/abc',
                'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
            ])
            ->assertCreated();

        $this->assertTrue($this->manager->pushSubscriptions()->exists());

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.push.destroy'), ['endpoint' => 'https://push.example/abc'])
            ->assertOk()
            ->assertJsonPath('subscribed', false);

        $this->assertFalse($this->manager->pushSubscriptions()->exists());
    }

    #[Test]
    public function store_is_gated_by_feature_flag(): void
    {
        config(['crm.push.enabled' => false]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.push.store'), [
                'endpoint' => 'https://push.example/abc',
                'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
            ])
            ->assertNotFound();
    }

    #[Test]
    public function push_command_sends_once_per_reason_and_respects_log(): void
    {
        config([
            'crm.push.enabled' => true,
            'webpush.vapid.public_key' => 'test-key',
        ]);
        Notification::fake();

        $this->subscribe($this->manager);

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subHour(),
        ]);

        $this->artisan('crm:tasks-push')->assertSuccessful();
        $this->artisan('crm:tasks-push')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, TaskPushNotification::class, 1);
        $this->assertSame(1, CrmTaskReminderLog::query()->where('channel', 'push')->count());
    }

    #[Test]
    public function push_command_is_silent_without_flag_or_subscription(): void
    {
        Notification::fake();

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subHour(),
        ]);

        // Флаг выключен.
        config(['crm.push.enabled' => false]);
        $this->artisan('crm:tasks-push')->assertSuccessful();

        // Флаг включён, но подписок нет.
        config(['crm.push.enabled' => true, 'webpush.vapid.public_key' => 'test-key']);
        $this->artisan('crm:tasks-push')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function toast_and_push_channels_are_independent(): void
    {
        config([
            'crm.push.enabled' => true,
            'webpush.vapid.public_key' => 'test-key',
        ]);
        Notification::fake();

        $this->subscribe($this->manager);

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subHour(),
        ]);

        // Сначала тост забрал повод…
        $this->actingAs($this->manager)->getJson(route('crm.notifications.poll'))->assertOk();

        // …push всё равно уходит: канал свой, отметка своя.
        $this->artisan('crm:tasks-push')->assertSuccessful();
        Notification::assertSentToTimes($this->manager, TaskPushNotification::class, 1);

        $this->assertSame(2, CrmTaskReminderLog::query()->count());
    }
}
