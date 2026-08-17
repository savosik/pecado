<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\TaskOutcome;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Crm\WatchedTaskEventNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-03: три исхода — успешно, с проблемой, перенести.
 */
class TaskOutcomeTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $watcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->watcher = User::factory()->create();
        $this->watcher->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->watcher->id]);
    }

    private function task(array $overrides = []): CrmTask
    {
        return CrmTask::factory()->create(array_merge([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ], $overrides));
    }

    #[Test]
    public function closing_with_success_stores_outcome(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), ['outcome' => 'success'])
            ->assertOk()
            ->assertJsonPath('task.outcome', 'success')
            ->assertJsonPath('task.outcome_label', 'Успешно');

        $this->assertSame(TaskOutcome::SUCCESS, $task->fresh()->outcome);
        $this->assertNotNull($task->fresh()->done_at);
    }

    #[Test]
    public function closing_without_outcome_defaults_to_success(): void
    {
        $task = $this->task();

        // Старые вызовы (галочка в списке, агент) закрывают без параметра.
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task))
            ->assertOk()
            ->assertJsonPath('task.outcome', 'success');
    }

    #[Test]
    public function closing_with_problem_requires_comment(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), ['outcome' => 'problem'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        // Задача при этом не закрыта.
        $this->assertSame(TaskStatus::OPEN, $task->fresh()->status);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), [
                'outcome' => 'problem',
                'comment' => 'Партнёр отказался: цена доставки',
            ])
            ->assertOk()
            ->assertJsonPath('task.outcome', 'problem');
    }

    #[Test]
    public function postpone_moves_due_keeps_status_and_writes_system_comment(): void
    {
        $task = $this->task(['due_at' => now()->addDay()->setTime(12, 0)]);

        $newDue = now()->addDays(3)->setTime(15, 30);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.postpone', $task), [
                'due_at' => $newDue->format('Y-m-d\TH:i'),
                'reason' => 'Партнёр в отпуске',
            ])
            ->assertOk()
            ->assertJsonPath('postponed_count', 1);

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::OPEN, $fresh->status);
        $this->assertSame($newDue->format('Y-m-d H:i'), $fresh->due_at->format('Y-m-d H:i'));

        // Системный комментарий фиксирует перенос и причину.
        $comment = CrmComment::query()->firstOrFail();
        $this->assertStringContainsString('Перенесена с', $comment->body);
        $this->assertStringContainsString('Партнёр в отпуске', $comment->body);
    }

    #[Test]
    public function reopening_task_clears_outcome(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), ['outcome' => 'success'])
            ->assertOk();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('outcome', null);

        $this->assertNull($task->fresh()->outcome);
    }

    #[Test]
    public function watcher_is_notified_about_close_and_postpone(): void
    {
        config(['notifications.mail.features.crm_tasks' => true]);
        Notification::fake();

        $task = $this->task();
        $task->watchers()->attach($this->watcher->id);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.postpone', $task), [
                'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ])
            ->assertOk();

        Notification::assertSentTo(
            $this->watcher,
            WatchedTaskEventNotification::class,
            fn ($notification): bool => $notification->event === 'postponed',
        );

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), ['outcome' => 'problem', 'comment' => 'Не вышло'])
            ->assertOk();

        Notification::assertSentTo(
            $this->watcher,
            WatchedTaskEventNotification::class,
            fn ($notification): bool => $notification->event === 'closed',
        );
    }

    #[Test]
    public function outcome_filter_round_trips_in_filters_snapshot(): void
    {
        $problem = $this->task();
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $problem), ['outcome' => 'problem', 'comment' => 'Сорвалось'])
            ->assertOk();

        $success = $this->task();
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $success), ['outcome' => 'success'])
            ->assertOk();

        $response = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index', ['preset' => 'all', 'outcome' => 'problem']))
            ->assertOk();

        $props = $response->viewData('page')['props'];

        // Ключ фильтра возвращается в снимке — известный класс багов фильтров.
        $this->assertSame('problem', $props['filters']['outcome']);
        $this->assertSame([$problem->id], array_column($props['tasks']['data'], 'id'));
    }

    #[Test]
    public function follow_up_still_works_with_problem_outcome(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), [
                'outcome' => 'problem',
                'comment' => 'Партнёр недоволен сроками',
                'follow_up' => [
                    'title' => 'Перезвонить с новым предложением',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('follow_up.title', 'Перезвонить с новым предложением');

        $this->assertSame(2, CrmTask::query()->count());
    }
}
