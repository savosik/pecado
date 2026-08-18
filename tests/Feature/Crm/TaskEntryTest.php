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
 * task-05: вход в CRM — блок «просрочено/сегодня/неделя» и счётчик в меню.
 */
class TaskEntryTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        // Границы групп «сегодня/просрочено» зависят от времени суток: после 23:00
        // задача «endOfDay − час» уже в прошлом, и ночной CI ловил лишнюю просрочку.
        $this->travelTo(now()->startOfDay()->addHours(12));

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function task(array $overrides = []): CrmTask
    {
        return CrmTask::factory()->create(array_merge([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
        ], $overrides));
    }

    #[Test]
    public function dashboard_groups_overdue_today_and_week(): void
    {
        // Границы считаем в зоне приложения: «вчера 23:59» — просрочка,
        // «сегодня 23:00» — сегодня, «послезавтра» — неделя.
        $overdue = $this->task(['due_at' => now()->subDay()->setTime(23, 59)]);
        $today = $this->task(['due_at' => now()->endOfDay()->subHour()]);
        $week = $this->task(['due_at' => now()->addDays(2)]);
        $far = $this->task(['due_at' => now()->addDays(30)]);

        $props = $this->actingAs($this->manager)
            ->get(route('crm.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['tasks'];

        $this->assertSame(1, $props['overdue']['count']);
        $this->assertSame($overdue->id, $props['overdue']['items'][0]['id']);

        $this->assertSame(1, $props['today']['count']);
        $this->assertSame($today->id, $props['today']['items'][0]['id']);

        $this->assertSame(1, $props['week']['count']);
        $this->assertSame($week->id, $props['week']['items'][0]['id']);

        // Далёкая задача не попадает ни в одну группу входа.
        $ids = array_merge(
            array_column($props['overdue']['items'], 'id'),
            array_column($props['today']['items'], 'id'),
            array_column($props['week']['items'], 'id'),
        );
        $this->assertNotContains($far->id, $ids);
    }

    #[Test]
    public function menu_counter_sums_overdue_and_today(): void
    {
        $this->task(['due_at' => now()->subDay()]);
        $this->task(['due_at' => now()->endOfDay()->subHour()]);
        $this->task(['due_at' => now()->addDays(3)]);

        $counters = $this->actingAs($this->manager)
            ->get(route('crm.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['crmCounters'];

        $this->assertSame(2, $counters['tasks']);
    }

    #[Test]
    public function counter_ignores_closed_tasks(): void
    {
        $task = $this->task(['due_at' => now()->subDay()]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $task), ['outcome' => 'success'])
            ->assertOk();

        $counters = $this->actingAs($this->manager)
            ->get(route('crm.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['crmCounters'];

        $this->assertSame(0, $counters['tasks']);
    }
}
