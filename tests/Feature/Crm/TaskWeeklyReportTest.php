<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Crm\TaskWeeklyReportNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-10: пятничный отчёт по задачам.
 */
class TaskWeeklyReportTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();
        config(['notifications.mail.features.crm_tasks' => true]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
    }

    private function weekActivity(): void
    {
        // Успешное закрытие вовремя.
        $done = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addHours(2),
        ]);
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $done), ['outcome' => 'success'])
            ->assertOk();

        // Закрытие с проблемой.
        $problem = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'title' => 'Сложная сделка',
        ]);
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $problem), ['outcome' => 'problem', 'comment' => 'Не сошлись'])
            ->assertOk();

        // Перенос.
        $moved = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDay(),
        ]);
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.postpone', $moved), [
                'due_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            ])
            ->assertOk();

        // Просрочка.
        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->subDays(2),
        ]);
    }

    #[Test]
    public function report_reaches_manager_and_head_with_correct_numbers(): void
    {
        Notification::fake();
        $this->weekActivity();

        $this->artisan('crm:tasks-weekly-report')->assertSuccessful();

        Notification::assertSentTo(
            $this->manager,
            TaskWeeklyReportNotification::class,
            function (TaskWeeklyReportNotification $notification): bool {
                return $notification->departmentRows === null
                    && $notification->stats['closed_total'] === 2
                    && $notification->stats['closed_success'] === 1
                    && $notification->stats['closed_problem'] === 1
                    && $notification->stats['postpones'] === 1
                    && $notification->stats['overdue_now'] >= 1
                    && in_array('Сложная сделка', $notification->stats['problem_titles'], true);
            },
        );

        Notification::assertSentTo(
            $this->head,
            TaskWeeklyReportNotification::class,
            fn (TaskWeeklyReportNotification $notification): bool => is_array($notification->departmentRows)
                && count($notification->departmentRows) === 1
                && $notification->departmentRows[0]['stats']['closed_total'] === 2,
        );
    }

    #[Test]
    public function double_run_in_same_week_sends_once(): void
    {
        Notification::fake();
        $this->weekActivity();

        $this->artisan('crm:tasks-weekly-report')->assertSuccessful();
        $this->artisan('crm:tasks-weekly-report')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, TaskWeeklyReportNotification::class, 1);
    }

    #[Test]
    public function silent_when_flag_off_or_no_activity(): void
    {
        Notification::fake();

        // Активности нет — писем нет.
        $this->artisan('crm:tasks-weekly-report')->assertSuccessful();
        Notification::assertNothingSent();

        // Флаг выключен — тоже тишина.
        config(['notifications.mail.features.crm_tasks' => false]);
        $this->weekActivity();
        $this->artisan('crm:tasks-weekly-report')->assertSuccessful();
        Notification::assertNothingSent();
    }

    #[Test]
    public function mail_views_render(): void
    {
        $this->weekActivity();

        $service = app(\App\Services\Crm\TaskWeeklyReportService::class);
        $stats = $service->managerStats(
            $this->manager,
            \Carbon\CarbonImmutable::now()->startOfWeek(),
            \Carbon\CarbonImmutable::now(),
        );

        // Личное письмо.
        $personal = (new TaskWeeklyReportNotification($stats, null, '11.08 — 15.08'))
            ->toMail($this->manager)
            ->render();
        $this->assertStringContainsString('Ваши задачи за неделю', (string) $personal);
        $this->assertStringContainsString('Сложная сделка', (string) $personal);

        // Сводка РОПа.
        $summary = (new TaskWeeklyReportNotification(
            $stats,
            [['name' => (string) $this->manager->name, 'stats' => $stats]],
            '11.08 — 15.08',
        ))->toMail($this->head)->render();
        $this->assertStringContainsString('Задачи отдела за неделю', (string) $summary);
        $this->assertStringContainsString((string) $this->manager->name, (string) $summary);
    }
}
