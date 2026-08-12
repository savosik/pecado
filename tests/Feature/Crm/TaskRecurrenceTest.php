<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\CrmTaskRecurrence;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\CrmTaskRecurrenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Автоповторяемые задачи (crm-29).
 */
class TaskRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function rule(array $attributes = []): CrmTaskRecurrence
    {
        return CrmTaskRecurrence::create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'title' => 'Обзвонить спящих',
            'priority' => 'normal',
            'weekdays' => [1, 2, 3, 4, 5],
            'due_time' => '13:30',
            'starts_on' => Carbon::parse('2026-08-01'),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function generateOn(string $date, int $horizon = 0): int
    {
        return app(CrmTaskRecurrenceService::class)
            ->generate(CarbonImmutable::parse($date), $horizon);
    }

    /**
     * Самая вероятная ошибка такой фичи и самая заметная для пользователя:
     * два одинаковых поручения на один день.
     */
    #[Test]
    public function running_twice_does_not_duplicate_the_task(): void
    {
        $this->rule();

        // 2026-08-12 — среда.
        $this->assertSame(1, $this->generateOn('2026-08-12'));
        $this->assertSame(0, $this->generateOn('2026-08-12'));

        $this->assertSame(1, CrmTask::query()->count());
    }

    #[Test]
    public function weekday_mask_is_respected(): void
    {
        $this->rule();

        // 2026-08-15 — суббота, её в маске будних дней нет.
        $this->assertSame(0, $this->generateOn('2026-08-15'));
        $this->assertSame(0, CrmTask::query()->count());
    }

    /**
     * «13:30» пользователя и время сервера не должны разъезжаться — на этих
     * граблях проект уже стоял в тесте последнего визита.
     */
    #[Test]
    public function due_time_lands_in_the_application_timezone(): void
    {
        $this->rule(['due_time' => '13:30']);

        $this->generateOn('2026-08-12');

        $task = CrmTask::query()->firstOrFail();

        $this->assertSame('2026-08-12 13:30', $task->due_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function the_horizon_does_not_fill_the_list_with_the_future(): void
    {
        $this->rule();

        // Со среды на два дня вперёд — среда, четверг, пятница.
        $this->assertSame(3, $this->generateOn('2026-08-12', horizon: 2));

        $this->assertSame(3, CrmTask::query()->count());
    }

    /**
     * Отмена цепочки — это `is_active = false`, а не удаление: уже созданные
     * задачи остаются в истории вместе с отчётами о закрытии.
     */
    #[Test]
    public function cancelling_the_chain_stops_generation_but_keeps_history(): void
    {
        $rule = $this->rule();

        $this->generateOn('2026-08-12');
        $this->assertSame(1, CrmTask::query()->count());

        $rule->update(['is_active' => false]);

        $this->assertSame(0, $this->generateOn('2026-08-13'));
        $this->assertSame(1, CrmTask::query()->count());
    }

    #[Test]
    public function the_window_of_validity_is_respected(): void
    {
        $this->rule([
            'starts_on' => Carbon::parse('2026-08-13'),
            'ends_on' => Carbon::parse('2026-08-14'),
        ]);

        // Среда — правило ещё не началось.
        $this->assertSame(0, $this->generateOn('2026-08-12'));
        // Четверг — в окне.
        $this->assertSame(1, $this->generateOn('2026-08-13'));
        // Понедельник — окно уже закрылось.
        $this->assertSame(0, $this->generateOn('2026-08-17'));
    }

    #[Test]
    public function the_generated_task_carries_the_template(): void
    {
        $client = User::factory()->create();

        $this->rule([
            'title' => 'Сверить дебиторку',
            'description' => 'Позвонить в бухгалтерию',
            'priority' => 'high',
            'client_user_id' => $client->id,
        ]);

        $this->generateOn('2026-08-12');

        $this->assertDatabaseHas('crm_tasks', [
            'title' => 'Сверить дебиторку',
            'description' => 'Позвонить в бухгалтерию',
            'priority' => 'high',
            'client_user_id' => $client->id,
            'assignee_id' => $this->manager->id,
        ]);
    }

    #[Test]
    public function a_rule_created_today_does_not_wait_for_the_night_run(): void
    {
        $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));

        $this->actingAs($this->manager)
            ->postJson(route('crm.task-recurrences.store'), [
                'title' => 'Обзвонить спящих',
                'assignee_id' => $this->manager->id,
                'weekdays' => [1, 2, 3, 4, 5],
                'due_time' => '13:30',
                'starts_on' => '2026-08-12',
            ])
            ->assertCreated();

        // Правило, заведённое утром на «сегодня в 13:30», обязано породить
        // задачу сразу, иначе первый день просто пропал бы.
        $this->assertDatabaseHas('crm_tasks', ['title' => 'Обзвонить спящих']);
    }

    #[Test]
    public function cancelling_through_the_endpoint_only_deactivates(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.task-recurrences.destroy', $rule))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('crm_task_recurrences', [
            'id' => $rule->id,
            'is_active' => false,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function a_colleagues_rule_needs_the_department_edit_permission(): void
    {
        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');

        $rule = CrmTaskRecurrence::create([
            'author_id' => $colleague->id,
            'assignee_id' => $colleague->id,
            'title' => 'Чужое правило',
            'weekdays' => [1],
            'due_time' => '10:00',
            'starts_on' => Carbon::parse('2026-08-01'),
        ]);

        // По умолчанию менеджеры взаимозаменяемы и правило коллеги доступно.
        $this->actingAs($this->manager)
            ->deleteJson(route('crm.task-recurrences.destroy', $rule))
            ->assertOk();

        // Без права на действия с чужим — 403.
        \Spatie\Permission\Models\Role::findByName('sales-manager')
            ->revokePermissionTo('crm-department.edit');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.task-recurrences.destroy', $rule))
            ->assertForbidden();
    }

    #[Test]
    public function validation_messages_are_in_russian(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.task-recurrences.store'), [
                'title' => 'Обзвон',
                'assignee_id' => $this->manager->id,
                'weekdays' => [],
                'due_time' => '25:99',
                'starts_on' => '2026-08-12',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weekdays', 'due_time'])
            ->assertJsonPath('errors.weekdays.0', 'Выберите хотя бы один день недели.')
            ->assertJsonPath('errors.due_time.0', 'Время указывается как ЧЧ:ММ, например 13:30.');
    }

    #[Test]
    public function the_command_is_safe_to_run_by_hand(): void
    {
        $this->rule();

        $this->artisan('crm:tasks-recur', ['--date' => '2026-08-12', '--horizon' => 0])
            ->assertSuccessful();
        $this->artisan('crm:tasks-recur', ['--date' => '2026-08-12', '--horizon' => 0])
            ->expectsOutputToContain('Новых задач по расписанию нет.')
            ->assertSuccessful();

        $this->assertSame(1, CrmTask::query()->count());
    }
}
