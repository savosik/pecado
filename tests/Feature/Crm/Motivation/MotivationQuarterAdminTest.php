<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyShare;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\PayslipService;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Квартальная премия руководителя (mot-38; формы B6, B12): утверждение,
 * распределение с контролем суммы, выплата, переоткрытие, строка в расчётном листе.
 */
class MotivationQuarterAdminTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $first;

    private PersonalManager $second;

    private CarbonImmutable $quarter;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->quarter = CarbonImmutable::now()->startOfQuarter();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->first = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Сухов']);
        $this->second = PersonalManager::factory()->create(['user_id' => User::factory()->create(['user_kind' => UserKind::STAFF->value])->id, 'name' => 'Курочкина']);

        app(MotivationSchemeInstaller::class)->install($this->quarter);
    }

    /**
     * Утверждённая премия с суммой: ступень задаётся прямо, чтобы не строить 8 новых партнёров.
     */
    private function approvedBonus(float $amount = 40_000): MotivationQuarterlyBonus
    {
        return MotivationQuarterlyBonus::query()->create([
            'quarter_start' => $this->quarter->toDateString(),
            'qualified_count' => 8,
            'step_reached' => 1,
            'amount' => $amount,
            'status' => MotivationQuarterlyBonus::STATUS_APPROVED,
            'snapshot' => ['threshold' => 100_000, 'steps' => config('motivation.default_parameters.quarterly_steps'), 'candidates' => 8, 'qualified' => []],
            'approved_by' => $this->head->id,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    #[TestDox('Страница руководителя: пересчёт, утверждение, переоткрытие; менеджеру закрыта')]
    public function page_recalculate_approve_reopen(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation/quarter/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/QuarterAdmin')
                ->where('data.qualified_count', 0)
                ->where('bonus.status', 'draft')
                ->has('distribution.rows', 2)
                ->where('can_edit', true));

        $bonusId = MotivationQuarterlyBonus::query()->value('id');

        $this->actingAs($this->head)->postJson("/crm/motivation/quarter/{$bonusId}/approve")->assertOk()->assertJsonPath('bonus.status', 'approved');
        $this->actingAs($this->head)->postJson("/crm/motivation/quarter/{$bonusId}/approve")->assertUnprocessable();
        $this->actingAs($this->head)->postJson("/crm/motivation/quarter/{$bonusId}/reopen")->assertOk()->assertJsonPath('bonus.status', 'draft');
        $this->actingAs($this->head)->postJson('/crm/motivation/quarter/recalculate')->assertOk()->assertJsonPath('bonus.status', 'draft');

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/quarter/admin')->assertForbidden();
    }

    #[Test]
    #[TestDox('Распределение: сумма долей обязана равняться премии; выплата только после распределения')]
    public function distribution_requires_exact_total(): void
    {
        $bonus = $this->approvedBonus(40_000);

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/quarter/{$bonus->id}/distribute", ['shares' => [
                ['manager_id' => $this->first->id, 'amount' => 25_000],
                ['manager_id' => $this->second->id, 'amount' => 10_000],
            ]])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Сумма долей 35 000 ₽ не равна сумме премии 40 000 ₽.']);

        $this->actingAs($this->head)->postJson("/crm/motivation/quarter/{$bonus->id}/paid")->assertUnprocessable();

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/quarter/{$bonus->id}/distribute", ['shares' => [
                ['manager_id' => $this->first->id, 'amount' => 30_000],
                ['manager_id' => $this->second->id, 'amount' => 10_000],
            ], 'reason' => 'По вкладу в новых партнёров'])
            ->assertOk()
            ->assertJsonPath('distribution.complete', true)
            ->assertJsonPath('distribution.total', 40_000);

        $this->assertSame(2, MotivationQuarterlyShare::query()->where('bonus_id', $bonus->id)->count());

        $this->actingAs($this->head)->postJson("/crm/motivation/quarter/{$bonus->id}/paid")->assertOk()->assertJsonPath('bonus.status', 'paid');
        $this->actingAs($this->head)
            ->postJson("/crm/motivation/quarter/{$bonus->id}/distribute", ['shares' => [['manager_id' => $this->first->id, 'amount' => 40_000]]])
            ->assertUnprocessable();
    }

    #[Test]
    #[TestDox('Доля попадает в расчётный лист за последний месяц квартала отдельной строкой и в итог не входит')]
    public function share_appears_in_payslip_of_last_quarter_month(): void
    {
        $bonus = $this->approvedBonus(40_000);
        MotivationQuarterlyShare::query()->create(['bonus_id' => $bonus->id, 'personal_manager_id' => $this->first->id, 'amount' => 30_000, 'reason' => 'По вкладу', 'author_id' => $this->head->id]);

        $calculations = app(PayrollCalculationService::class);
        $lastMonth = $this->quarter->endOfQuarter()->startOfMonth();
        $firstMonth = $this->quarter;

        $slipLast = app(PayslipService::class)->build($calculations->ensureDraft($this->first->id, $lastMonth));
        $this->assertNotNull($slipLast['quarterly_share']);
        $this->assertSame(30_000.0, $slipLast['quarterly_share']['amount']);
        $this->assertSame('По вкладу', $slipLast['quarterly_share']['reason']);
        $this->assertTrue($slipLast['quarterly_share']['distributed']);
        $this->assertSame(0, count(array_filter($slipLast['lines'], fn (array $l): bool => str_contains(mb_strtolower($l['label']), 'кварт'))), 'Премия не входит в строки начисления месяца');

        $slipSecond = app(PayslipService::class)->build($calculations->ensureDraft($this->second->id, $lastMonth));
        $this->assertSame(0.0, $slipSecond['quarterly_share']['amount']);
        $this->assertFalse($slipSecond['quarterly_share']['distributed']);

        if (! $firstMonth->equalTo($lastMonth)) {
            $slipFirst = app(PayslipService::class)->build($calculations->ensureDraft($this->first->id, $firstMonth));
            $this->assertNull($slipFirst['quarterly_share'], 'В первом месяце квартала строки нет');
        }
    }
}
