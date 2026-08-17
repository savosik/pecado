<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\CrmTaskRecurrence;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Crm\TaskAssignedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-01: соисполнители, личный контроль, трудоёмкость.
 */
class TaskCollabTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $colleague;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->colleague = User::factory()->create();
        $this->colleague->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->colleague->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
    }

    #[Test]
    public function task_is_created_with_co_assignees_and_each_gets_notified(): void
    {
        config(['notifications.mail.features.crm_tasks' => true]);
        Notification::fake();

        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Совместная сверка',
            'assignee_id' => $this->manager->id,
            'co_assignee_ids' => [$this->colleague->id],
        ])->assertCreated()
            ->assertJsonPath('co_assignees.0.id', $this->colleague->id);

        $task = CrmTask::query()->firstOrFail();
        $this->assertTrue($task->isAssigneeOf($this->colleague->id));

        // Соисполнителю уведомление уходит, автору-ответственному — нет.
        Notification::assertSentTo($this->colleague, TaskAssignedNotification::class);
        Notification::assertNotSentTo($this->manager, TaskAssignedNotification::class);
    }

    #[Test]
    public function co_assignee_sees_task_in_mine_preset_and_can_update_status(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->head->id,
            'assignee_id' => $this->head->id,
        ]);
        $task->coAssignees()->attach($this->colleague->id);

        $listed = $this->actingAs($this->colleague)
            ->get(route('crm.tasks.index', ['preset' => 'mine']))
            ->assertOk();

        $tasks = $listed->viewData('page')['props']['tasks']['data'];
        $this->assertContains($task->id, array_column($tasks, 'id'));

        // Соисполнитель меняет статус…
        $this->actingAs($this->colleague)
            ->patchJson(route('crm.tasks.update', $task), ['status' => 'in_progress'])
            ->assertOk();

        // …но не переназначает ответственного: право reassign остаётся за автором и РОПом.
        $this->actingAs($this->colleague)
            ->patchJson(route('crm.tasks.update', $task), ['assignee_id' => $this->colleague->id])
            ->assertOk();

        $this->assertSame($this->head->id, $task->fresh()->assignee_id);
    }

    #[Test]
    public function responsible_assignee_is_not_duplicated_into_pivot(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Без дублей',
            'assignee_id' => $this->manager->id,
            'co_assignee_ids' => [$this->manager->id, $this->colleague->id, $this->colleague->id],
        ])->assertCreated();

        $task = CrmTask::query()->firstOrFail();

        $this->assertSame([$this->colleague->id], $task->coAssignees()->pluck('users.id')->all());
    }

    #[Test]
    public function co_assignee_requires_crm_access(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Не для посторонних',
            'assignee_id' => $this->manager->id,
            'co_assignee_ids' => [$outsider->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['co_assignee_ids.0']);
    }

    #[Test]
    public function watcher_sees_task_but_cannot_edit_it(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ]);
        $task->watchers()->attach($this->colleague->id);

        $this->actingAs($this->colleague)
            ->getJson(route('crm.tasks.show', $task))
            ->assertOk()
            ->assertJsonPath('is_watched', true);

        $this->actingAs($this->colleague)
            ->patchJson(route('crm.tasks.update', $task), ['title' => 'Перехват'])
            ->assertForbidden();
    }

    #[Test]
    public function manager_takes_own_visible_task_on_watch_and_releases_it(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.watch', $task))
            ->assertOk()
            ->assertJsonPath('is_watched', true);

        $this->assertTrue($task->fresh()->isWatchedBy($this->manager->id));

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.tasks.unwatch', $task))
            ->assertOk()
            ->assertJsonPath('is_watched', false);

        $this->assertFalse($task->fresh()->isWatchedBy($this->manager->id));
    }

    #[Test]
    public function only_department_editor_puts_task_on_watch_for_someone_else(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ]);

        // Рядовой менеджер не может навязать контроль коллеге.
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.watch', $task), ['user_id' => $this->colleague->id])
            ->assertForbidden();

        // РОП — может.
        $this->actingAs($this->head)
            ->postJson(route('crm.tasks.watch', $task), ['user_id' => $this->colleague->id])
            ->assertOk();

        $this->assertTrue($task->fresh()->isWatchedBy($this->colleague->id));
    }

    #[Test]
    public function watching_preset_lists_only_watched_active_tasks(): void
    {
        $watched = CrmTask::factory()->create([
            'author_id' => $this->head->id,
            'assignee_id' => $this->head->id,
        ]);
        $watched->watchers()->attach($this->manager->id);

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index', ['preset' => 'watching']))
            ->assertOk();

        $tasks = $response->viewData('page')['props']['tasks']['data'];

        $this->assertSame([$watched->id], array_column($tasks, 'id'));
    }

    #[Test]
    public function estimate_is_optional_stored_and_labeled(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'С оценкой',
            'assignee_id' => $this->manager->id,
            'estimate_minutes' => 90,
        ])->assertCreated()
            ->assertJsonPath('estimate_minutes', 90)
            ->assertJsonPath('estimate_label', '1 ч 30 мин');

        // Без оценки — тоже нормально.
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Без оценки',
            'assignee_id' => $this->manager->id,
        ])->assertCreated()
            ->assertJsonPath('estimate_minutes', null)
            ->assertJsonPath('estimate_label', null);
    }

    #[Test]
    public function recurrence_estimate_is_inherited_by_generated_tasks(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.task-recurrences.store'), [
            'title' => 'Ежедневная сверка',
            'assignee_id' => $this->manager->id,
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'due_time' => '10:00',
            'starts_on' => now()->toDateString(),
            'estimate_minutes' => 45,
        ])->assertCreated();

        $rule = CrmTaskRecurrence::query()->firstOrFail();
        $this->assertSame(45, $rule->estimate_minutes);

        $task = CrmTask::query()->where('title', 'Ежедневная сверка')->firstOrFail();
        $this->assertSame(45, $task->estimate_minutes);
    }
}
