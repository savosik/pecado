<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SalaryTeamTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $profile;

    private PersonalManager $other;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Курочкина']);

        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        $this->other = PersonalManager::factory()->create(['user_id' => $colleague->id, 'name' => 'Сухов']);

        PersonalManager::factory()->create(['user_id' => null, 'name' => 'Без учётки']);   // в сводку не входит
    }

    #[Test]
    #[TestDox('Сводка по отделу: только РОП, черновики создаются для всех менеджеров с учёткой')]
    public function team_summary_for_head_only(): void
    {
        $this->actingAs($this->manager)->get('/crm/salary/team')->assertForbidden();

        PayrollManualAdjustment::factory()->forMonth(Carbon::now())->create(['personal_manager_id' => $this->profile->id]);

        $this->actingAs($this->head)
            ->get('/crm/salary/team')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Salary/Team')
                ->has('rows', 2)
                ->where('rows.0.manager.name', 'Курочкина')
                ->where('rows.0.calculation.status', 'draft')
                ->where('rows.0.calculation.total', 75000)
                ->where('rows.0.amounts.extra_income', 5000)
                ->where('rows.1.calculation.total', 70000)
                ->where('totals.total', 145000)
                ->where('totals.salary', 140000)
                ->where('statuses.draft', 2)
                ->where('can_edit', true));

        $this->assertSame(2, PayrollCalculation::query()->count());

        $this->actingAs($this->manager)->get('/crm/salary/team/export')->assertForbidden();
        $this->actingAs($this->head)
            ->get('/crm/salary/team/export?month='.Carbon::now()->format('Y-m'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Test]
    #[TestDox('Утвердить → выплачено → переоткрыть; на каждом шаге охрана статуса')]
    public function approval_lifecycle(): void
    {
        $this->actingAs($this->head)->getJson('/crm/salary/team/data')->assertOk();
        $calc = PayrollCalculation::query()->forManager($this->profile->id)->firstOrFail();

        $this->actingAs($this->manager)->postJson("/crm/salary/calculations/{$calc->id}/approve")->assertForbidden();

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/paid")
            ->assertStatus(422);

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/approve", ['comment' => 'проверено с ведомостью'])
            ->assertOk()
            ->assertJsonPath('calculation.status', 'approved')
            ->assertJsonPath('calculation.comment', 'проверено с ведомостью')
            ->assertJsonPath('calculation.is_frozen', true);

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/approve")
            ->assertStatus(422);

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/recalculate")
            ->assertStatus(422);

        // Утверждённый снимок не меняется от новых данных.
        PayrollManualAdjustment::factory()->forMonth(Carbon::now())->create(['personal_manager_id' => $this->profile->id]);
        $this->actingAs($this->head)
            ->getJson('/crm/salary/team/data')
            ->assertOk()
            ->assertJsonPath('rows.0.calculation.total', 70000)
            ->assertJsonPath('rows.0.calculation.status', 'approved');

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/paid")
            ->assertOk()
            ->assertJsonPath('calculation.status', 'paid');

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/reopen", ['comment' => 'доначислить ТГ'])
            ->assertOk()
            ->assertJsonPath('calculation.status', 'draft')
            ->assertJsonPath('calculation.version', 2)
            ->assertJsonPath('calculation.total', 75000);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/team/data')
            ->assertJsonPath('rows.0.calculation.version', 2)
            ->assertJsonPath('rows.0.calculation.status', 'draft')
            ->assertJsonPath('statuses.draft', 2);

        $this->assertSame(PayrollCalculation::STATUS_PAID, $calc->fresh()->status);
    }

    #[Test]
    #[TestDox('Пересчёт черновика по кнопке подхватывает новые входы')]
    public function manual_recalculate(): void
    {
        $this->actingAs($this->head)->getJson('/crm/salary/team/data')->assertOk();
        $calc = PayrollCalculation::query()->forManager($this->other->id)->firstOrFail();

        PayrollManualAdjustment::factory()->forMonth(Carbon::now())->correction(-1000)->create(['personal_manager_id' => $this->other->id]);

        $this->actingAs($this->head)
            ->postJson("/crm/salary/calculations/{$calc->id}/recalculate")
            ->assertOk()
            ->assertJsonPath('calculation.total', 69000);
    }
}
