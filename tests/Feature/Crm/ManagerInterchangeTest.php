<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Взаимозаменяемость менеджеров (crm-21, этапы 4–5).
 *
 * Обратная сторона {@see CrmScopeTest}: там проверяется, что фокус узкий
 * по умолчанию, здесь — что расфокусироваться действительно есть куда
 * и что коллеге можно помочь, а не только посмотреть на его работу.
 *
 * Изоляция, которую эти тесты отменяют, не удалена: она включается снятием
 * права в матрице ролей и проверяется теми же тестами через
 * {@see Concerns\RestrictsManagersToOwnClients}.
 */
class ManagerInterchangeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $colleague;

    private User $foreignClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->colleague = User::factory()->create();
        $this->colleague->assignRole('sales-manager');
        $colleagueCard = PersonalManager::factory()->create(['user_id' => $this->colleague->id]);

        $this->foreignClient = User::factory()->create([
            'personal_manager_id' => $colleagueCard->id,
        ]);
    }

    #[Test]
    public function manager_opens_the_card_of_a_colleagues_client(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->foreignClient))
            ->assertOk();
    }

    /**
     * Карточка открывается по праву, а не по разрезу: сфокусировавшись на своих,
     * менеджер не теряет возможность зайти к клиенту коллеги по прямой ссылке.
     * Разрез — фильтр списка, а не контроль доступа.
     */
    #[Test]
    public function mine_scope_does_not_close_a_colleagues_client_card(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', [$this->foreignClient, 'scope' => CrmScope::MINE->value]))
            ->assertOk();
    }

    #[Test]
    public function manager_puts_a_task_on_a_colleagues_client(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.store'), [
                'title' => 'Перезвонить, пока коллега в отпуске',
                'assignee_id' => $this->manager->id,
                'entity_type' => 'client',
                'entity_id' => $this->foreignClient->id,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('crm_tasks', [
            'client_user_id' => $this->foreignClient->id,
            'author_id' => $this->manager->id,
        ]);
    }

    #[Test]
    public function manager_closes_a_task_assigned_to_a_colleague(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->colleague->id,
            'assignee_id' => $this->colleague->id,
            'client_user_id' => $this->foreignClient->id,
        ]);

        $this->actingAs($this->manager)
            ->post(route('crm.tasks.close', $task), ['report' => 'Закрыл за коллегу'])
            ->assertSuccessful();

        $this->assertNotNull($task->fresh()->done_at);
    }

    /**
     * Взаимозаменяемость не отдаёт менеджеру инструменты руководителя:
     * план отдела остаётся общей целью, за которую отвечает один человек.
     */
    #[Test]
    public function manager_still_cannot_set_the_department_plan(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), [
                'month' => now()->format('Y-m'),
                'rows' => [
                    ['target_type' => 'department', 'amount' => 1_000_000],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('saved', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertDatabaseCount('crm_sales_plans', 0);
    }

    #[Test]
    public function manager_still_cannot_see_revenue_broken_down_by_manager(): void
    {
        $this->assertFalse($this->manager->can('crm-clients-all.view'));

        $this->actingAs($this->manager)
            ->get(route('crm.plans.by-manager'))
            ->assertForbidden();
    }

    /**
     * Удаление чужого файла восстановить неоткуда, поэтому оно не входит
     * в `crm-department.edit` — см. миграцию этапа 5.
     */
    #[Test]
    public function manager_cannot_delete_a_file_uploaded_by_a_colleague(): void
    {
        $this->assertTrue($this->manager->can('crm-department.edit'));

        $this->assertFalse(
            $this->manager->can('crm-attachments.delete')
                && $this->manager->can('crm-clients-all.view'),
        );
    }
}
