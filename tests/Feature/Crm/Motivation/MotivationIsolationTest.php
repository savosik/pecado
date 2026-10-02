<?php

namespace Tests\Feature\Crm\Motivation;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\ParameterOrderService;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Работники не видят мотивацию друг друга (требование заказчика 02.10.2026).
 *
 * Раздел открыт менеджерам правом crm-motivation.view; каждый экран обязан
 * показывать только собственные данные — чужой `manager` в адресе игнорируется,
 * персональные условия коллег и список работников не отдаются.
 */
class MotivationIsolationTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $alice;

    private User $bob;

    private PersonalManager $aliceProfile;

    private PersonalManager $bobProfile;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();

        $this->alice = User::factory()->staff()->create();
        $this->alice->assignRole('sales-manager-crm');
        $this->aliceProfile = PersonalManager::factory()->create(['user_id' => $this->alice->id, 'name' => 'Алиса']);

        $this->bob = User::factory()->staff()->create();
        $this->bob->assignRole('sales-manager-crm');
        $this->bobProfile = PersonalManager::factory()->create(['user_id' => $this->bob->id, 'name' => 'Борис']);

        app(MotivationSchemeInstaller::class)->install($this->month);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'мой месяц' => ['/crm/motivation'],
            'мои клиенты' => ['/crm/motivation/base'],
            'выпали из ритма' => ['/crm/motivation/rhythm'],
            'разбудить' => ['/crm/motivation/wake'],
            'новые' => ['/crm/motivation/new-partners'],
            'выданные' => ['/crm/motivation/packages'],
            'долги' => ['/crm/motivation/debts'],
            'расчётный лист' => ['/crm/motivation/payslip'],
            'откуда план' => ['/crm/motivation/plan'],
            'что предложить' => ['/crm/motivation/focus'],
            'кому предложить' => ['/crm/motivation/focus/partners'],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    #[TestDox('Экран показывает работнику только его данные, даже если в адресе чужой manager')]
    public function page_ignores_foreign_manager(string $path): void
    {
        $this->actingAs($this->alice)
            ->get($path.'?manager='.$this->bobProfile->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('manager.id', $this->aliceProfile->id)
                ->where('can_see_all', false)
                ->where('scope_options', fn ($options) => collect($options)->pluck('id')->doesntContain($this->bobProfile->id)));
    }

    #[Test]
    #[TestDox('Чужой расчётный лист не открыть ни по номеру расчёта, ни возражением')]
    public function foreign_payslip_and_objection_are_closed(): void
    {
        $calculations = app(PayrollCalculationService::class);
        $bobs = $calculations->approve($calculations->ensureDraft($this->bobProfile->id, $this->month), $this->alice);

        $this->actingAs($this->alice)
            ->postJson('/crm/motivation/objection', ['calculation' => $bobs->id, 'reason' => 'Не согласна с чужим расчётом'])
            ->assertStatus(403);

        $this->actingAs($this->alice)
            ->getJson('/crm/motivation/payslip/data?manager='.$this->bobProfile->id)
            ->assertOk()
            ->assertJsonPath('manager.id', $this->aliceProfile->id);
    }

    #[Test]
    #[TestDox('«Параметры» отдают работнику общий приказ и только его персональные условия')]
    public function settings_hide_colleagues_personal_terms(): void
    {
        $orders = app(ParameterOrderService::class);
        $head = User::factory()->staff()->create();
        $head->assignRole('sales-head');
        // База гарантии — средняя оплата труда: у каждого своя и коллеге не показывается.
        $orders->savePersonal($this->bobProfile->id, 'motivation_guarantee', ['share' => 0.9, 'base' => 127_497.6, 'until' => null], $head, 'База гарантии');
        $orders->savePersonal($this->aliceProfile->id, 'motivation_guarantee', ['share' => 0.9, 'base' => 101_678.6, 'until' => null], $head, 'База гарантии');

        $this->actingAs($this->alice)
            ->get('/crm/motivation/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can_edit', false)
                ->where('managers', [])
                ->has('personal', 1)
                ->where('personal.0.manager_id', $this->aliceProfile->id));

        $this->actingAs($head)
            ->get('/crm/motivation/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('personal', 2)->has('managers', 2));
    }

    #[Test]
    #[TestDox('Экраны руководителя — ведомость, планы, пул, исключения долга — работнику закрыты')]
    public function head_screens_are_closed(): void
    {
        foreach (['/crm/motivation/team', '/crm/motivation/team/export', '/crm/motivation/approval', '/crm/motivation/plans', '/crm/motivation/pool/admin', '/crm/motivation/debt-exclusions', '/crm/motivation/focus-list', '/crm/motivation/quarter/admin', '/crm/motivation/forecast', '/crm/motivation/health', '/crm/motivation/invoices', '/crm/salary/team'] as $path) {
            $this->actingAs($this->alice)->get($path)->assertForbidden();
        }
    }
}
