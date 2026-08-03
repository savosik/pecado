<?php

namespace Tests\Feature\Crm;

use App\Enums\UserKind;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сотрудники и служебные учётки не попадают в CRM.
 *
 * 1С шлёт партнёрами всех подряд и проставляет им personal_manager_id, поэтому
 * закупщики и админы висели в списке клиентов, считались в покрытии задачами
 * и им можно было поставить план продаж.
 */
class CrmUserKindScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function salesHead(): User
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        return $head;
    }

    /**
     * Учётка сотрудника, которой 1С проставила менеджера, — ровно тот случай,
     * из-за которого всё и затевалось.
     */
    private function staffWithManager(): User
    {
        return User::factory()->staff()->create(['personal_manager_id' => $this->profile->id]);
    }

    #[Test]
    public function manager_does_not_see_staff_accounts_among_clients(): void
    {
        User::factory()->count(2)->create(['personal_manager_id' => $this->profile->id]);
        $this->staffWithManager();
        User::factory()->service()->create(['personal_manager_id' => $this->profile->id]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 2));
    }

    #[Test]
    public function sales_head_does_not_see_staff_accounts_among_clients(): void
    {
        User::factory()->count(3)->create(['personal_manager_id' => $this->profile->id]);
        $this->staffWithManager();

        $this->actingAs($this->salesHead())
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 3));
    }

    #[Test]
    public function staff_client_card_is_not_found_in_crm(): void
    {
        $staff = $this->staffWithManager();

        // Именно 404, а не 403: для CRM такой учётки не существует.
        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $staff->id))
            ->assertNotFound();
    }

    #[Test]
    public function dashboard_counters_exclude_staff(): void
    {
        User::factory()->count(2)->create(['personal_manager_id' => $this->profile->id]);
        $this->staffWithManager();

        $this->actingAs($this->manager)
            ->get(route('crm.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.visible_clients', 2)
                ->where('coverage.clients_total', 2)
            );
    }

    #[Test]
    public function department_counter_excludes_staff(): void
    {
        User::factory()->count(4)->create(['personal_manager_id' => $this->profile->id]);
        $this->staffWithManager();

        $this->actingAs($this->salesHead())
            ->get(route('crm.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.department_clients', 4)
            );
    }

    #[Test]
    public function plan_cannot_be_set_for_staff_account(): void
    {
        $staff = $this->staffWithManager();

        $this->actingAs($this->manager)
            ->post(route('crm.plans.store'), [
                'month' => '2026-08',
                'rows' => [
                    ['target_type' => 'client', 'target_id' => $staff->id, 'amount' => '100000'],
                ],
            ])
            ->assertSuccessful();

        // Строка вне скоупа молча пропускается — плана в базе нет.
        $this->assertDatabaseMissing('crm_sales_plans', [
            'target_type' => 'client',
            'target_id' => $staff->id,
        ]);
    }

    #[Test]
    public function staff_account_is_not_offered_in_client_search(): void
    {
        $staff = $this->staffWithManager();
        $client = User::factory()->create([
            'personal_manager_id' => $this->profile->id,
            'name' => $staff->name,
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.tasks.entities', ['type' => 'client', 'query' => $staff->name]));

        $response->assertOk();

        $ids = array_column($response->json(), 'id');
        $this->assertContains($client->id, $ids);
        $this->assertNotContains($staff->id, $ids);
    }

    #[Test]
    public function new_user_is_a_client_by_default(): void
    {
        $user = User::create([
            'name' => 'Из 1С',
            'email' => 'from-erp@example.test',
            'password' => 'secret-password',
        ]);

        $this->assertSame(UserKind::CLIENT, $user->fresh()->user_kind);
    }
}
