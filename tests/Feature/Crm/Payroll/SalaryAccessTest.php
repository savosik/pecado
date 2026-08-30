<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

class SalaryAccessTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $head;

    private PersonalManager $otherProfile;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Курочкина']);
        User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);

        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        $this->otherProfile = PersonalManager::factory()->create(['user_id' => $colleague->id, 'name' => 'Сухов']);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
    }

    #[Test]
    #[TestDox('Менеджер видит свою зарплату; чужой manager в адресе игнорируется')]
    public function manager_sees_only_own_salary(): void
    {
        $this->actingAs($this->manager)
            ->get('/crm/salary?manager='.$this->otherProfile->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Salary/Index')
                ->where('manager.id', $this->managerProfile->id)
                ->where('manager.name', 'Курочкина')
                ->where('can_see_all', false)
                ->where('can_edit', false)
                ->where('calculation.status', 'draft')
                ->where('calculation.total', 70000)
                ->has('explanations.kpi_bonus.description')
                ->has('months', 12));
    }

    #[Test]
    #[TestDox('РОП открывает любого менеджера и видит список для выбора')]
    public function head_sees_any_manager(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/salary?manager='.$this->otherProfile->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('manager.id', $this->otherProfile->id)
                ->where('can_see_all', true)
                ->where('can_edit', true)
                ->has('scope_options', 2));
    }

    #[Test]
    #[TestDox('РОП без карточки менеджера и без выбора получает пустое состояние, а не ошибку')]
    public function head_without_profile_gets_empty_state(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/salary')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('manager', null)
                ->where('calculation', null));
    }

    #[Test]
    #[TestDox('Опрос отдаёт тот же payload и интервал')]
    public function polling_endpoint_returns_payload(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/crm/salary/data?month='.now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('manager.id', $this->managerProfile->id)
            ->assertJsonPath('calculation.status', 'draft')
            ->assertJsonPath('poll_seconds', (int) config('payroll.poll_seconds'))
            ->assertJsonPath('timeline.total_count', 0)
            ->assertJsonStructure(['server_time', 'calculation' => ['breakdown', 'inputs', 'params'], 'timeline' => ['rows']]);
    }

    #[Test]
    #[TestDox('Будущий месяц в адресе сводится к текущему')]
    public function future_month_falls_back_to_current(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/crm/salary/data?month='.now()->addMonths(2)->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('month', now()->format('Y-m'));
    }

    #[Test]
    #[TestDox('Без права crm-salary.view раздел закрыт')]
    public function requires_permission(): void
    {
        $role = Role::where('name', 'sales-manager')->where('guard_name', 'web')->firstOrFail();
        $role->revokePermissionTo('crm-salary.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)->get('/crm/salary')->assertForbidden();
        $this->actingAs($this->manager)->getJson('/crm/salary/data')->assertForbidden();
    }
}
