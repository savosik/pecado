<?php

namespace Tests\Feature\Crm\Motivation;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * «Мой месяц» (карточка mot-26): доступ, состав ответа, заморозка, калькулятор.
 *
 * Главное здесь — доступ: раздел не показывается менеджерам до ввода Положения
 * в действие (решение заказчика от 08.09.2026), и это должно ломаться тестом,
 * а не обнаруживаться на бою.
 */
class MotivationPageTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $headProfile;

    private User $manager;

    private PersonalManager $managerProfile;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
        $this->headProfile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Курочкина']);

        app(MotivationSchemeInstaller::class)->install(CarbonImmutable::now()->startOfMonth());
    }

    #[Test]
    #[TestDox('Менеджер по продажам видит раздел, но только свой расчёт: чужой manager в адресе игнорируется')]
    public function sales_manager_sees_only_own_month(): void
    {
        $this->assertTrue($this->manager->can('crm-motivation.view'), 'Положение действует — работник видит свою мотивацию');
        $this->assertFalse($this->manager->can('crm-motivation.edit'), 'Параметры, планы и ведомость — только руководителю');

        $this->actingAs($this->manager)
            ->get('/crm/motivation?manager='.$this->headProfile->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('manager.id', $this->managerProfile->id)
                ->where('can_see_all', false)
                ->where('can_edit', false));

        $this->actingAs($this->manager)
            ->getJson('/crm/motivation/data?manager='.$this->headProfile->id)
            ->assertOk()
            ->assertJsonPath('manager.id', $this->managerProfile->id);
    }

    #[Test]
    #[TestDox('Сотрудник без права на мотивацию раздел не видит — ни страницу, ни данные')]
    public function staff_without_permission_is_kept_out(): void
    {
        // В CRM пускает, а права на мотивацию нет.
        $outsider = User::factory()->staff()->create();
        $outsider->givePermissionTo('crm-dashboard.view');

        $this->actingAs($outsider)->get('/crm/motivation')->assertForbidden();
        $this->actingAs($outsider)->get('/crm/motivation/data')->assertForbidden();
        $this->actingAs($outsider)->post('/crm/motivation/simulate', [])->assertForbidden();
    }

    #[Test]
    #[TestDox('Руководитель открывает раздел и видит свой расчёт по схеме 2.2')]
    public function head_sees_the_page(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Index')
                ->where('manager.id', $this->headProfile->id)
                ->where('can_see_all', true)
                ->where('can_edit', true)
                ->where('calculation.on_scheme_v2', true)
                ->where('calculation.status', 'draft')
                ->where('calculation.frozen', false)
                ->has('calculation.lines', 5)
                ->where('calculation.lines.0.key', 'salary')
                ->where('calculation.lines.1.key', 'base_sales')
                ->where('calculation.lines.4.key', 'overdue')
                ->has('months', 12));
    }

    #[Test]
    #[TestDox('Сумма строк равна итогу — приёмочное условие экрана')]
    public function lines_add_up_to_the_total(): void
    {
        $data = $this->actingAs($this->head)->getJson('/crm/motivation/data')->assertOk()->json('calculation');

        $sum = array_sum(array_map(fn (array $line): float => (float) $line['amount'], $data['lines']));

        $this->assertEqualsWithDelta((float) $data['total'], $sum, 0.01);
        $this->assertSame(75_000.0, (float) $data['total'], 'Оклад 70 000 + надбавка 5 000, показатели нулевые');
    }

    #[Test]
    #[TestDox('Менеджер с выданным правом видит только себя, чужой manager игнорируется')]
    public function granted_manager_sees_only_own_month(): void
    {
        $this->manager->givePermissionTo(Permission::findByName('crm-motivation.view'));

        $this->actingAs($this->manager)
            ->get('/crm/motivation?manager='.$this->headProfile->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('manager.id', $this->managerProfile->id)
                ->where('manager.name', 'Курочкина')
                ->where('can_see_all', false)
                ->where('can_edit', false));
    }

    #[Test]
    #[TestDox('Утверждённый месяц: заморожен, рычагов и прогноза нет')]
    public function frozen_month_has_no_levers(): void
    {
        $service = app(PayrollCalculationService::class);
        $draft = $service->ensureDraft($this->headProfile->id, CarbonImmutable::now()->startOfMonth());
        $service->approve($draft, $this->head);

        $data = $this->actingAs($this->head)->getJson('/crm/motivation/data')->assertOk()->json('calculation');

        $this->assertTrue($data['frozen']);
        $this->assertSame('approved', $data['status']);
        $this->assertNotNull($data['approved_at']);
        $this->assertSame([], $data['levers']);
        $this->assertNull($data['forecast']);
    }

    #[Test]
    #[TestDox('Калькулятор считает той же формулой и возвращает разницу с фактом')]
    public function simulate_uses_the_same_formula(): void
    {
        $response = $this->actingAs($this->head)->postJson('/crm/motivation/simulate', [
            'base_revenue' => 6_000_000,
            'new_partners_revenue' => 210_000,
            'focus_revenue' => 150_000,
            'overdue_integral' => 800_000 * 20,
        ])->assertOk()->json();

        // Без плана П1 не начисляется: 6 300 + 1 500 − 8 000 < 0 → переменная часть 0.
        $this->assertSame(75_000.0, (float) $response['total']);
        $this->assertSame(0.0, (float) $response['delta']);
        $this->assertSame(6_300.0, (float) $response['variable_part']['p2']);
        $this->assertSame(8_000.0, (float) $response['variable_part']['k1']);
        $this->assertTrue($response['variable_part']['floored']);
    }

    #[Test]
    #[TestDox('Калькулятор отклоняет неполный запрос по-русски')]
    public function simulate_validates_input(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/motivation/simulate', ['base_revenue' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_partners_revenue', 'focus_revenue', 'overdue_integral'])
            ->assertJsonFragment(['Не передана база вычета.']);
    }

    #[Test]
    #[TestDox('Раскрытие строки отдаёт перечень из снимка')]
    public function evidence_returns_rows_from_the_snapshot(): void
    {
        $this->actingAs($this->head)
            ->getJson('/crm/motivation/evidence?line=overdue')
            ->assertOk()
            ->assertJson(['line' => 'overdue', 'rows' => [], 'total' => 0]);

        $this->actingAs($this->head)
            ->getJson('/crm/motivation/evidence?line=unknown')
            ->assertUnprocessable();
    }

    #[Test]
    #[TestDox('Месяц до ввода схемы 2.2 честно помечен как считаемый по прежней схеме')]
    public function month_before_scheme_v2_is_flagged(): void
    {
        $previous = CarbonImmutable::now()->subMonth()->format('Y-m');

        $data = $this->actingAs($this->head)->getJson('/crm/motivation/data?month='.$previous)->assertOk()->json('calculation');

        $this->assertFalse($data['on_scheme_v2']);
        $this->assertSame([], $data['lines']);
        $this->assertNotEmpty($data['warnings']);
    }
}
