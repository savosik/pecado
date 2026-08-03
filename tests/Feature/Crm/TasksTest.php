<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Crm\TaskAssignedNotification;
use App\Notifications\Crm\TaskDueSoonNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $managerProfile->id]);

        $this->colleague = User::factory()->create();
        $this->colleague->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->colleague->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Выставить счёт',
            'description' => 'По заявке от вторника',
            'assignee_id' => $this->manager->id,
            'priority' => 'high',
            'due_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
        ], $overrides);
    }

    #[Test]
    public function manager_creates_task_for_own_client(): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), $this->payload([
            'entity_type' => 'client',
            'entity_id' => $this->client->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('title', 'Выставить счёт')
            ->assertJsonPath('priority_label', 'Высокий')
            ->assertJsonPath('entity.type', 'client');

        $task = CrmTask::query()->firstOrFail();

        $this->assertSame($this->manager->id, $task->author_id);
        // Привязка сводится к клиенту — денормализация делается моделью, а не контроллером.
        $this->assertSame($this->client->id, $task->client_user_id);
    }

    #[Test]
    public function task_can_be_created_without_any_link(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('entity', null);

        $task = CrmTask::query()->firstOrFail();

        $this->assertNull($task->related_type);
        $this->assertNull($task->client_user_id);
    }

    #[Test]
    public function task_binds_to_order_and_lands_in_client_timeline(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), $this->payload([
            'entity_type' => 'order',
            'entity_id' => $order->id,
        ]))->assertCreated();

        $timeline = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $timeline);
        $this->assertSame('task', $timeline[0]['type']);
        $this->assertSame('Выставить счёт', $timeline[0]['title']);
        $this->assertSame('order', $timeline[0]['entity']['type']);
    }

    #[Test]
    public function client_timeline_mixes_comments_and_tasks_in_one_chronology(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.comments.store'), [
            'entity_type' => 'client',
            'entity_id' => $this->client->id,
            'body' => 'Договорились созвониться',
        ])->assertCreated();

        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), $this->payload([
            'entity_type' => 'client',
            'entity_id' => $this->client->id,
        ]))->assertCreated();

        $timeline = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->json();

        $this->assertSame(2, $timeline['total']);
        $this->assertEqualsCanonicalizing(
            ['comment', 'task'],
            array_column($timeline['data'], 'type'),
        );
    }

    #[Test]
    public function task_for_foreign_client_is_not_created(): void
    {
        $foreignManager = PersonalManager::factory()->create();
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignManager->id]);

        $this->actingAs($this->manager)->postJson(route('crm.tasks.store'), $this->payload([
            'entity_type' => 'client',
            'entity_id' => $foreignClient->id,
        ]))->assertNotFound();

        $this->assertSame(0, CrmTask::query()->count());
    }

    #[Test]
    public function assignee_must_have_crm_access(): void
    {
        $storekeeper = User::factory()->create();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), $this->payload(['assignee_id' => $storekeeper->id]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.assignee_id.0',
                'Исполнителем можно назначить только сотрудника с доступом в CRM.',
            );
    }

    #[Test]
    public function validation_errors_are_in_russian(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), $this->payload(['title' => '']))
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Введите, что нужно сделать.');
    }

    #[Test]
    public function assignee_can_close_task_and_done_at_is_recorded(): void
    {
        $task = CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)->create();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $task->refresh();

        $this->assertNotNull($task->done_at);

        // Переоткрытие снимает факт выполнения — иначе отчёт о сроках врал бы.
        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['status' => 'open'])
            ->assertOk();

        $this->assertNull($task->refresh()->done_at);
    }

    #[Test]
    public function assignee_cannot_reassign_task_to_third_person(): void
    {
        $task = CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)->create();
        $third = User::factory()->create();
        $third->assignRole('sales-manager');

        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['assignee_id' => $third->id])
            ->assertOk();

        // Запрос прошёл, но исполнитель не сменился: перевесить задачу может только автор.
        $this->assertSame($this->manager->id, $task->refresh()->assignee_id);
    }

    #[Test]
    public function author_can_reassign_task(): void
    {
        $task = CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->create();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['assignee_id' => $this->colleague->id])
            ->assertOk();

        $this->assertSame($this->colleague->id, $task->refresh()->assignee_id);
    }

    #[Test]
    public function foreign_task_is_invisible_and_unchangeable(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $task = CrmTask::factory()->by($stranger)->assignedTo($stranger)->create();

        $this->actingAs($this->manager)->getJson(route('crm.tasks.show', $task))->assertForbidden();
        $this->actingAs($this->manager)
            ->patchJson(route('crm.tasks.update', $task), ['title' => 'Чужая'])
            ->assertForbidden();
        $this->actingAs($this->manager)->deleteJson(route('crm.tasks.destroy', $task))->assertForbidden();
    }

    #[Test]
    public function head_of_sales_sees_tasks_of_the_whole_department(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $task = CrmTask::factory()->by($this->colleague)->assignedTo($this->colleague)->create();

        $this->actingAs($head)->getJson(route('crm.tasks.show', $task))->assertOk();
    }

    #[Test]
    public function assignee_only_can_delete_nothing_but_author_can(): void
    {
        $task = CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)->create();

        $this->actingAs($this->manager)->deleteJson(route('crm.tasks.destroy', $task))->assertForbidden();
        $this->actingAs($this->colleague)->deleteJson(route('crm.tasks.destroy', $task))->assertOk();

        $this->assertSoftDeleted($task);
    }

    #[Test]
    public function section_shows_full_list_including_unlinked_and_third_party_tasks(): void
    {
        // Поставлена коллегой мне и ни к чему не привязана — из общего списка
        // она выпадать не должна.
        CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)->create(['title' => 'Без привязки']);
        CrmTask::factory()->by($this->manager)->assignedTo($this->colleague)->create(['title' => 'Поручил коллеге']);

        $response = $this->actingAs($this->manager)->get(route('crm.tasks.index', ['preset' => 'all']));

        $response->assertOk();
        $titles = array_column($response->viewData('page')['props']['tasks']['data'], 'title');

        $this->assertEqualsCanonicalizing(['Без привязки', 'Поручил коллеге'], $titles);
    }

    #[Test]
    public function presets_are_filters_over_the_same_list(): void
    {
        CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)->create(['title' => 'Мне']);
        CrmTask::factory()->by($this->manager)->assignedTo($this->colleague)->create(['title' => 'От меня']);
        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->overdue()->create(['title' => 'Просрочено']);

        $mine = $this->tasksFor(['preset' => 'mine']);
        $authored = $this->tasksFor(['preset' => 'authored']);
        $overdue = $this->tasksFor(['preset' => 'overdue']);

        $this->assertEqualsCanonicalizing(['Мне', 'Просрочено'], $mine);
        $this->assertEqualsCanonicalizing(['От меня', 'Просрочено'], $authored);
        $this->assertSame(['Просрочено'], $overdue);
    }

    #[Test]
    public function overdue_task_is_marked_and_counted(): void
    {
        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->overdue()->create();

        $response = $this->actingAs($this->manager)->get(route('crm.tasks.index'));
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['tasks']['data'][0]['is_overdue']);
        $this->assertSame(1, $props['counters']['overdue']);
    }

    #[Test]
    public function closed_task_is_never_overdue(): void
    {
        $task = CrmTask::factory()->by($this->manager)->assignedTo($this->manager)
            ->create(['due_at' => now()->subWeek(), 'status' => TaskStatus::DONE]);

        $this->assertFalse($task->isOverdue());
        $this->assertSame(0, CrmTask::query()->overdue()->count());
    }

    #[Test]
    public function entity_panel_lists_tasks_of_one_entity_only(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->on($order)->create(['title' => 'По заказу']);
        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->create(['title' => 'Сама по себе']);

        $titles = array_column(
            $this->actingAs($this->manager)
                ->getJson(route('crm.tasks.list', ['entity_type' => 'order', 'entity_id' => $order->id]))
                ->assertOk()
                ->json('data'),
            'title',
        );

        $this->assertSame(['По заказу'], $titles);
    }

    #[Test]
    public function dashboard_widget_shows_today_and_overdue(): void
    {
        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->overdue()->create();
        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)
            ->create(['due_at' => now()->addHours(2)]);
        CrmTask::factory()->by($this->manager)->assignedTo($this->manager)
            ->create(['due_at' => now()->addMonth()]);

        $props = $this->actingAs($this->manager)->get(route('crm.dashboard'))->viewData('page')['props'];

        $this->assertCount(2, $props['tasks']['items']);
        $this->assertSame(1, $props['tasks']['overdue_count']);
        $this->assertSame(1, $props['tasks']['today_count']);
    }

    #[Test]
    public function without_permission_section_is_closed(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('sales-manager');
        $outsider->revokePermissionTo('crm-tasks.view');
        $role = \Spatie\Permission\Models\Role::findByName('sales-manager');
        $role->revokePermissionTo('crm-tasks.view');

        $this->actingAs($outsider)->get(route('crm.tasks.index'))->assertForbidden();
    }

    #[Test]
    public function own_unlinked_task_can_be_discussed_and_have_files(): void
    {
        // Задача без привязки не сводится к клиенту. Правило «клиента нет — значит,
        // только РОП» отобрало бы у менеджера обсуждение его же собственной задачи.
        $task = CrmTask::factory()->by($this->manager)->assignedTo($this->manager)->create();

        $comment = $this->actingAs($this->manager)->postJson(route('crm.comments.store'), [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'body' => 'Начал делать',
        ])->assertCreated()->json();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.comments.update', $comment['id']), ['body' => 'Уточнение'])
            ->assertOk();

        $this->actingAs($this->manager)
            ->getJson(route('crm.attachments.index', ['entity_type' => 'task', 'entity_id' => $task->id]))
            ->assertOk();
    }

    #[Test]
    public function stranger_cannot_discuss_foreign_unlinked_task(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $task = CrmTask::factory()->by($stranger)->assignedTo($stranger)->create();

        $this->actingAs($this->manager)->postJson(route('crm.comments.store'), [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'body' => 'Подсмотрел',
        ])->assertNotFound();
    }

    #[Test]
    public function assignment_email_is_gated_by_feature_flag(): void
    {
        Notification::fake();
        config(['notifications.mail.features.crm_tasks' => false]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), $this->payload(['assignee_id' => $this->colleague->id]))
            ->assertCreated();

        Notification::assertNothingSent();

        config(['notifications.mail.features.crm_tasks' => true]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), $this->payload(['assignee_id' => $this->colleague->id]))
            ->assertCreated();

        Notification::assertSentTo($this->colleague, TaskAssignedNotification::class);
    }

    #[Test]
    public function self_assigned_task_does_not_send_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.crm_tasks' => true]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), $this->payload(['assignee_id' => $this->manager->id]))
            ->assertCreated();

        Notification::assertNothingSent();
    }

    #[Test]
    public function reminder_command_notifies_about_tomorrow_and_fresh_overdue(): void
    {
        Notification::fake();
        config(['notifications.mail.features.crm_tasks' => true]);

        CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)
            ->create(['due_at' => now()->addDay()->setTime(12, 0), 'title' => 'Завтра']);
        CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)
            ->create(['due_at' => now()->subHours(3), 'title' => 'Только что просрочена']);
        // Забытая неделю назад — повторно не напоминаем, иначе письма перестанут читать.
        CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)
            ->create(['due_at' => now()->subWeek(), 'title' => 'Забытая']);

        $this->artisan('crm:tasks-remind')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, TaskDueSoonNotification::class, 2);
    }

    #[Test]
    public function reminder_command_sends_nothing_when_flag_is_off(): void
    {
        Notification::fake();
        config(['notifications.mail.features.crm_tasks' => false]);

        CrmTask::factory()->by($this->colleague)->assignedTo($this->manager)
            ->create(['due_at' => now()->addDay()]);

        $this->artisan('crm:tasks-remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function tasksFor(array $query): array
    {
        $response = $this->actingAs($this->manager)->get(route('crm.tasks.index', $query));

        return array_column($response->viewData('page')['props']['tasks']['data'], 'title');
    }
}
