<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\CrmTaskReminderLog;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Crm\TaskDueSoonNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-08: единый reminder-контур — тосты, идемпотентность, сброс при переносе.
 */
class TaskReminderTest extends TestCase
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

    #[Test]
    public function poll_returns_due_reminder_once_and_counter(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subHour(),
        ]);

        $first = $this->actingAs($this->manager)
            ->getJson(route('crm.notifications.poll'))
            ->assertOk()
            ->json();

        $this->assertSame(1, $first['counters']['tasks']);
        $this->assertCount(1, $first['reminders']);
        $this->assertSame('due', $first['reminders'][0]['kind']);
        $this->assertSame($task->id, $first['reminders'][0]['task']['id']);
        $this->assertTrue($first['reminders'][0]['sticky']);

        // Второй опрос (другая вкладка) тот же повод уже не получает.
        $second = $this->actingAs($this->manager)
            ->getJson(route('crm.notifications.poll'))
            ->assertOk()
            ->json();

        $this->assertCount(0, $second['reminders']);
    }

    #[Test]
    public function assigned_reminder_fires_for_foreign_author_only(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ]);
        $foreign = CrmTask::factory()->create([
            'author_id' => $head->id,
            'assignee_id' => $this->manager->id,
        ]);

        $reminders = $this->actingAs($this->manager)
            ->getJson(route('crm.notifications.poll'))
            ->assertOk()
            ->json('reminders');

        $this->assertCount(1, $reminders);
        $this->assertSame('assigned', $reminders[0]['kind']);
        $this->assertSame($foreign->id, $reminders[0]['task']['id']);
    }

    #[Test]
    public function postpone_resets_due_reminders_so_they_fire_again(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subHour(),
        ]);

        $this->actingAs($this->manager)->getJson(route('crm.notifications.poll'));
        $this->assertSame(1, CrmTaskReminderLog::query()->where('kind', 'due')->count());

        // Перенос на час назад от текущего момента — срок снова «наступил».
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.postpone', $task), [
                'due_at' => now()->subMinutes(30)->format('Y-m-d\TH:i'),
            ])
            ->assertOk();

        $this->assertSame(0, CrmTaskReminderLog::query()->where('kind', 'due')->count());

        $reminders = $this->actingAs($this->manager)
            ->getJson(route('crm.notifications.poll'))
            ->json('reminders');

        $this->assertCount(1, $reminders);
        $this->assertSame('due', $reminders[0]['kind']);
    }

    #[Test]
    public function mail_command_double_run_does_not_duplicate(): void
    {
        config(['notifications.mail.features.crm_tasks' => true]);
        Notification::fake();

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDay()->setTime(12, 0),
        ]);

        $this->artisan('crm:tasks-remind')->assertSuccessful();
        $this->artisan('crm:tasks-remind')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, TaskDueSoonNotification::class, 1);
        $this->assertSame(1, CrmTaskReminderLog::query()
            ->where('kind', 'due_soon')
            ->where('channel', 'mail')
            ->count());
    }

    #[Test]
    public function ancient_overdue_does_not_flood_toasts(): void
    {
        // Задача, просроченная на неделю, не вываливается тостом при входе.
        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subWeek(),
        ]);

        $reminders = $this->actingAs($this->manager)
            ->getJson(route('crm.notifications.poll'))
            ->json('reminders');

        $this->assertCount(0, $reminders);
    }
}
