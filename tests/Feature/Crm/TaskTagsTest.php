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
 * Теги задач и чек-лист при создании (фидбек по эпику task-00).
 */
class TaskTagsTest extends TestCase
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
    public function task_is_created_with_tags_and_checklist_in_one_request(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Обзвон по оплатам',
            'assignee_id' => $this->manager->id,
            'tags' => ['Оплата', 'Срочное', 'Оплата'],
            'checklist' => ['Позвонить Гевее', '  ', 'Позвонить Ромашке'],
        ])->assertCreated()
            ->assertJsonPath('checklist_total', 2)
            ->assertJsonPath('tags', ['Оплата', 'Срочное']);

        $task = CrmTask::query()->firstOrFail();
        $this->assertSame(
            ['Позвонить Гевее', 'Позвонить Ромашке'],
            $task->checklistItems->pluck('title')->all(),
        );
    }

    #[Test]
    public function tags_are_replaced_on_update_and_survive_untouched_updates(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'С тегами',
            'assignee_id' => $this->manager->id,
            'tags' => ['Дебиторка'],
        ])->assertCreated();

        $task = CrmTask::query()->firstOrFail();

        // Правка без поля tags тегов не трогает.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['title' => 'С тегами v2'])
            ->assertOk()
            ->assertJsonPath('tags', ['Дебиторка']);

        // Явная передача — полная замена набора.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['tags' => ['Отгрузка']])
            ->assertOk()
            ->assertJsonPath('tags', ['Отгрузка']);
    }

    #[Test]
    public function tag_filter_narrows_list_and_round_trips(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Оплатная', 'assignee_id' => $this->manager->id, 'tags' => ['Оплата'],
        ])->assertCreated();
        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), [
            'title' => 'Обычная', 'assignee_id' => $this->manager->id,
        ])->assertCreated();

        $props = $this->actingAs($this->manager)
            ->get(route('crm.tasks.index', ['preset' => 'all', 'tag' => 'Оплата']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Оплата', $props['filters']['tag']);
        $this->assertSame(['Оплатная'], array_column($props['tasks']['data'], 'title'));

        // Существующие теги отдаются в опциях — для подсказок и фильтра.
        $this->assertContains('Оплата', $props['options']['tags']);
    }
}
