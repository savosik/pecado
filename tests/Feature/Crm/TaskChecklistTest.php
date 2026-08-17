<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\CrmTaskRecurrence;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-02: todo-чекбоксы внутри задачи.
 */
class TaskChecklistTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $colleague;

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
    }

    private function task(array $overrides = []): CrmTask
    {
        return CrmTask::factory()->create(array_merge([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ], $overrides));
    }

    #[Test]
    public function items_are_added_in_order_and_counted_in_list(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.checklist.store', $task), ['title' => 'Первый'])
            ->assertCreated();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.checklist.store', $task), ['title' => 'Второй'])
            ->assertCreated()
            ->assertJsonPath('checklist_total', 2)
            ->assertJsonPath('items.0.title', 'Первый')
            ->assertJsonPath('items.1.title', 'Второй');

        // Счётчики приходят в список через withCount — без загрузки пунктов.
        $listed = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index'))
            ->assertOk();

        $row = collect($listed->viewData('page')['props']['tasks']['data'])
            ->firstWhere('id', $task->id);

        $this->assertSame(2, $row['checklist_total']);
        $this->assertSame(0, $row['checklist_done']);
    }

    #[Test]
    public function toggle_records_who_and_when(): void
    {
        $task = $this->task();
        $task->coAssignees()->attach($this->colleague->id);
        $item = $task->checklistItems()->create(['title' => 'Позвонить', 'position' => 1]);

        // Соисполнитель отмечает пункт — фиксируется его авторство.
        $this->actingAs($this->colleague)
            ->patchJson(route('crm.tasks.checklist.update', [$task, $item]), ['is_done' => true])
            ->assertOk()
            ->assertJsonPath('checklist_done', 1)
            ->assertJsonPath('items.0.done_by.id', $this->colleague->id);

        $this->assertNotNull($item->fresh()->done_at);

        // Снятие галочки стирает автора и время.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.checklist.update', [$task, $item]), ['is_done' => false])
            ->assertOk()
            ->assertJsonPath('checklist_done', 0);

        $this->assertNull($item->fresh()->done_by_id);
    }

    #[Test]
    public function outsider_cannot_touch_foreign_checklist(): void
    {
        $task = $this->task();
        $item = $task->checklistItems()->create(['title' => 'Секрет', 'position' => 1]);

        $this->actingAs($this->colleague)
            ->postJson(route('crm.tasks.checklist.store', $task), ['title' => 'Чужой пункт'])
            ->assertForbidden();

        $this->actingAs($this->colleague)
            ->patchJson(route('crm.tasks.checklist.update', [$task, $item]), ['is_done' => true])
            ->assertForbidden();
    }

    #[Test]
    public function item_of_another_task_is_not_reachable_through_own_task(): void
    {
        $own = $this->task();
        $foreign = $this->task([
            'author_id' => $this->colleague->id,
            'assignee_id' => $this->colleague->id,
        ]);
        $foreignItem = $foreign->checklistItems()->create(['title' => 'Чужое', 'position' => 1]);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.checklist.update', [$own, $foreignItem]), ['is_done' => true])
            ->assertNotFound();
    }

    #[Test]
    public function recurrence_checklist_template_is_copied_into_generated_tasks(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.task-recurrences.store'), [
            'title' => 'Сверка с чек-листом',
            'assignee_id' => $this->manager->id,
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'due_time' => '09:00',
            'starts_on' => now()->toDateString(),
            'checklist' => ['Выгрузить остатки', 'Сверить цены'],
        ])->assertCreated();

        $rule = CrmTaskRecurrence::query()->firstOrFail();
        $this->assertSame(['Выгрузить остатки', 'Сверить цены'], $rule->checklist);

        $task = CrmTask::query()->where('title', 'Сверка с чек-листом')->firstOrFail();
        $this->assertSame(
            ['Выгрузить остатки', 'Сверить цены'],
            $task->checklistItems->pluck('title')->all(),
        );
    }

    #[Test]
    public function show_returns_full_checklist(): void
    {
        $task = $this->task();
        $task->checklistItems()->create(['title' => 'Пункт', 'position' => 1]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.show', $task))
            ->assertOk()
            ->assertJsonPath('checklist_total', 1)
            ->assertJsonPath('checklist.0.title', 'Пункт');
    }
}
