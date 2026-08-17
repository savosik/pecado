<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-06: календарь задач — фид диапазона, скоуп, фильтр менеджеров.
 */
class TaskCalendarTest extends TestCase
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
    public function feed_returns_only_open_tasks_with_due_in_range(): void
    {
        $inside = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDays(2),
        ]);
        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDays(40),
        ]);
        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => null,
        ]);
        $closed = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDay(),
            'status' => 'done',
        ]);

        $data = $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.calendar-feed', [
                'from' => now()->toDateString(),
                'to' => now()->addDays(7)->toDateString(),
            ]))
            ->assertOk()
            ->json('data');

        $this->assertSame([$inside->id], array_column($data, 'id'));
        $this->assertNotContains($closed->id, array_column($data, 'id'));
    }

    #[Test]
    public function feed_rejects_oversized_range(): void
    {
        $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.calendar-feed', [
                'from' => now()->toDateString(),
                'to' => now()->addDays(100)->toDateString(),
            ]))
            ->assertStatus(422);
    }

    #[Test]
    public function head_filters_department_feed_by_manager(): void
    {
        $mine = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDay(),
        ]);
        $others = CrmTask::factory()->create([
            'author_id' => $this->colleague->id,
            'assignee_id' => $this->colleague->id,
            'due_at' => now()->addDay(),
        ]);

        $all = $this->actingAs($this->head)
            ->getJson(route('crm.tasks.calendar-feed', [
                'from' => now()->toDateString(),
                'to' => now()->addDays(7)->toDateString(),
            ]))
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(
            [$mine->id, $others->id],
            array_column($all, 'id'),
        );

        $filtered = $this->actingAs($this->head)
            ->getJson(route('crm.tasks.calendar-feed', [
                'from' => now()->toDateString(),
                'to' => now()->addDays(7)->toDateString(),
                'manager_ids' => [$this->colleague->id],
            ]))
            ->assertOk()
            ->json('data');

        $this->assertSame([$others->id], array_column($filtered, 'id'));
    }

    #[Test]
    public function co_assignee_sees_task_in_own_calendar(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->head->id,
            'assignee_id' => $this->head->id,
            'due_at' => now()->addDay(),
        ]);
        $task->coAssignees()->attach($this->manager->id);

        $data = $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.calendar-feed', [
                'from' => now()->toDateString(),
                'to' => now()->addDays(7)->toDateString(),
                'scope' => 'mine',
            ]))
            ->assertOk()
            ->json('data');

        $this->assertContains($task->id, array_column($data, 'id'));
    }

    #[Test]
    public function calendar_page_renders(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.tasks.calendar'))
            ->assertOk();
    }
}
