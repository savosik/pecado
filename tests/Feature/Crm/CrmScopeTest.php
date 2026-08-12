<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Разрез «только мои / весь отдел» (crm-21).
 *
 * Проверяется главный инвариант: право задаёт границу возможного, разрез —
 * фокус внутри неё, и по умолчанию фокус узкий. Отсутствие параметра `scope`
 * должно означать «мои», иначе curl, сохранённая закладка и выгрузка молча
 * отдают отдел целиком.
 */
class CrmScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $ownCard;

    private PersonalManager $foreignCard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->ownCard = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->foreignCard = PersonalManager::factory()->create();
    }

    private function seesDepartment(User $actor): User
    {
        $actor->givePermissionTo('crm-department.view');
        $actor->forgetCachedPermissions();

        return $actor;
    }

    #[Test]
    public function default_scope_is_mine_even_for_someone_who_sees_the_department(): void
    {
        User::factory()->count(2)->create(['personal_manager_id' => $this->ownCard->id]);
        User::factory()->count(3)->create(['personal_manager_id' => $this->foreignCard->id]);

        $this->actingAs($this->seesDepartment($this->manager))
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 2)
                ->where('filters.scope', CrmScope::MINE->value));
    }

    #[Test]
    public function department_scope_widens_to_colleagues_clients(): void
    {
        User::factory()->count(2)->create(['personal_manager_id' => $this->ownCard->id]);
        User::factory()->count(3)->create(['personal_manager_id' => $this->foreignCard->id]);

        $this->actingAs($this->seesDepartment($this->manager))
            ->get(route('crm.clients.index', ['scope' => CrmScope::DEPARTMENT->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 5)
                ->where('filters.scope', CrmScope::DEPARTMENT->value));
    }

    /**
     * Значение вне разрешённого не проверяется, а гасится — тот же приём,
     * что с подставленным в адрес чужим manager_id.
     */
    #[Test]
    public function department_scope_collapses_to_mine_without_the_permission(): void
    {
        User::factory()->count(2)->create(['personal_manager_id' => $this->ownCard->id]);
        User::factory()->count(3)->create(['personal_manager_id' => $this->foreignCard->id]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['scope' => CrmScope::DEPARTMENT->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 2)
                ->where('filters.scope', CrmScope::MINE->value));
    }

    /**
     * У РОПа без карточки менеджера «мои» — пустой экран: за ним не закреплён
     * ни один партнёр. Показывать пустоту вместо отдела бессмысленно.
     */
    #[Test]
    public function actor_without_manager_card_always_gets_the_department(): void
    {
        User::factory()->count(3)->create(['personal_manager_id' => $this->foreignCard->id]);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->get(route('crm.clients.index', ['scope' => CrmScope::MINE->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 3)
                ->where('filters.scope', CrmScope::DEPARTMENT->value));
    }

    /**
     * На экране задач «мои» — это про участие, а не про закреплённых партнёров:
     * менеджер спрашивает «что на мне», а не «что по моим клиентам».
     *
     * Проверяется на пресете «Все»: пресет по умолчанию («Мне») и так сводит
     * список к своим задачам, и разрез на нём не виден.
     */
    #[Test]
    public function mine_scope_on_tasks_narrows_to_participation(): void
    {
        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');

        \App\Models\CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'title' => 'Моя задача',
        ]);
        \App\Models\CrmTask::factory()->create([
            'author_id' => $colleague->id,
            'assignee_id' => $colleague->id,
            'title' => 'Чужая задача',
        ]);

        $actor = $this->seesDepartment($this->manager);

        $this->actingAs($actor)
            ->get(route('crm.tasks.index', ['preset' => 'all']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasks.total', 1)
                ->where('filters.scope', CrmScope::MINE->value));

        $this->actingAs($actor)
            ->get(route('crm.tasks.index', ['preset' => 'all', 'scope' => CrmScope::DEPARTMENT->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.total', 2));
    }
}
