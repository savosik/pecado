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
 * task-04: раздел v2 — пины, корзины срока, догрузка порций, лента завершённых.
 */
class TaskSectionV2Test extends TestCase
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
    public function pinned_task_is_personal_and_comes_first(): void
    {
        $plain = $this->task(['due_at' => now()->addHour()]);
        $pinned = $this->task(['due_at' => now()->addDays(5)]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.pin', $pinned))
            ->assertOk()
            ->assertJsonPath('is_pinned', true);

        $rows = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index'))
            ->viewData('page')['props']['tasks']['data'];

        // Закреплённая — первой, несмотря на более поздний срок.
        $this->assertSame($pinned->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['is_pinned']);

        // У коллеги с доступом к отделу пин не виден — закрепление личное.
        $this->actingAs($this->manager)->deleteJson(route('crm.tasks.unpin', $pinned))->assertOk();
        $this->assertSame(0, $pinned->pinnedBy()->count());

        // Просто проверим и вторую задачу — без пина порядок по сроку.
        $rows = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index'))
            ->viewData('page')['props']['tasks']['data'];
        $this->assertSame($plain->id, $rows[0]['id']);
    }

    #[Test]
    public function closing_task_drops_all_pins(): void
    {
        $task = $this->task();
        $task->pinnedBy()->attach($this->manager->id);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), ['outcome' => 'success'])
            ->assertOk();

        $this->assertSame(0, $task->pinnedBy()->count());
    }

    #[Test]
    public function due_bucket_groups_by_application_timezone(): void
    {
        $overdue = $this->task(['due_at' => now()->subDays(2)]);
        $today = $this->task(['due_at' => now()->endOfDay()->subMinute()]);
        $tomorrow = $this->task(['due_at' => now()->addDay()]);
        $later = $this->task(['due_at' => now()->addDays(30)]);
        $none = $this->task(['due_at' => null]);

        $rows = collect($this->actingAs($this->manager)
            ->get(route('crm.tasks.index'))
            ->viewData('page')['props']['tasks']['data'])
            ->keyBy('id');

        $this->assertSame('overdue', $rows[$overdue->id]['due_bucket']);
        $this->assertSame('today', $rows[$today->id]['due_bucket']);
        $this->assertSame('tomorrow', $rows[$tomorrow->id]['due_bucket']);
        $this->assertSame('later', $rows[$later->id]['due_bucket']);
        $this->assertSame('none', $rows[$none->id]['due_bucket']);
    }

    #[Test]
    public function data_endpoint_pages_without_duplicates(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->task(['due_at' => now()->addDays($i + 1), 'title' => "Задача {$i}"]);
        }

        $first = $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.data', ['per_page' => 5]))
            ->assertOk()
            ->json();

        $second = $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.data', ['per_page' => 5, 'page' => 2]))
            ->assertOk()
            ->json();

        $ids = array_merge(array_column($first['data'], 'id'), array_column($second['data'], 'id'));

        $this->assertCount(7, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    #[Test]
    public function completed_preset_lists_closed_tasks_fresh_first(): void
    {
        $open = $this->task();

        $early = $this->task();
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $early), ['outcome' => 'success'])
            ->assertOk();
        $early->forceFill(['done_at' => now()->subDays(3)])->save();

        $recent = $this->task();
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $recent), ['outcome' => 'problem', 'comment' => 'Сложности'])
            ->assertOk();

        $rows = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index', ['preset' => 'completed']))
            ->viewData('page')['props']['tasks']['data'];

        $this->assertSame([$recent->id, $early->id], array_column($rows, 'id'));
        $this->assertNotContains($open->id, array_column($rows, 'id'));
        $this->assertSame('closed', $rows[0]['due_bucket']);
    }
}
