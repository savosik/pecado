<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Калькулятор с ползунками считает тем же калькулятором, что и снимок.
 */
class SalarySimulateTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $profile;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->month = Carbon::now()->format('Y-m');

        $period = Carbon::now()->startOfMonth();
        CrmSalesPlan::factory()->forMonth($period)->forManager($this->profile)->create(['amount' => 1_000_000]);

        // Два плановых партнёра, купил один — множитель по лестнице ниже единицы.
        foreach (['Альфа' => 400000.0, 'Бета' => 0.0] as $name => $fact) {
            $client = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => $name]);
            CrmSalesPlan::factory()->forMonth($period)->forClient($client)->create(['amount' => 500000]);

            if ($fact > 0) {
                $shipment = Shipment::create([
                    'uuid' => (string) Str::uuid(), 'user_id' => $client->id,
                    'date' => $period->copy()->addDay()->toDateString(), 'erp_created_at' => $period->copy()->addDay(),
                    'status' => 'completed', 'currency_code' => 'RUB', 'total_amount' => $fact,
                ]);
                ShipmentItem::create([
                    'shipment_id' => $shipment->id, 'product_id' => Product::factory()->create()->id,
                    'quantity' => 1, 'price' => $fact, 'total' => $fact, 'subtotal' => $fact,
                ]);
            }
        }
    }

    #[Test]
    #[TestDox('Ползунки на факте дают ту же цифру, что и снимок')]
    public function simulation_at_fact_matches_snapshot(): void
    {
        $page = $this->actingAs($this->manager)->getJson('/crm/salary/data')->assertOk()->json();
        $snapshotTotal = $page['calculation']['total'];

        $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', [
                'month' => $this->month,
                'revenue' => $page['calculation']['inputs']['revenue'],
                'active_clients' => $page['calculation']['inputs']['active_count'],
                'penalty' => $page['calculation']['kpi']['penalty'] ?? 0,
            ])
            ->assertOk()
            ->assertJsonPath('total', $snapshotTotal);
    }

    #[Test]
    #[TestDox('Ретроспектива прошлого месяца считается по данным того месяца, а не текущего')]
    public function simulation_of_a_past_month_uses_that_month(): void
    {
        // Прошлый месяц прожит иначе: другой план и другая отгрузка. Если запрос
        // калькулятора потеряет месяц, ползунки применятся к текущему месяцу, и
        // разбор ретроспективы будет чужим — молча, без единой ошибки.
        $past = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        CrmSalesPlan::factory()->forMonth($past)->forManager($this->profile)->create(['amount' => 2_000_000]);

        $client = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Гамма']);
        CrmSalesPlan::factory()->forMonth($past)->forClient($client)->create(['amount' => 900_000]);
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $client->id,
            'date' => $past->copy()->addDay()->toDateString(), 'erp_created_at' => $past->copy()->addDay(),
            'status' => 'completed', 'currency_code' => 'RUB', 'total_amount' => 800_000,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id, 'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'price' => 800_000, 'total' => 800_000, 'subtotal' => 800_000,
        ]);

        $pastMonth = $past->format('Y-m');
        $page = $this->actingAs($this->manager)
            ->getJson('/crm/salary/data?month='.$pastMonth)
            ->assertOk()
            ->json();

        $this->assertSame($pastMonth, $page['month']);
        $inputs = $page['calculation']['inputs'];
        $this->assertEqualsWithDelta(800_000.0, (float) $inputs['revenue'], 0.01);

        $response = $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', [
                'month' => $pastMonth,
                'revenue' => (float) $inputs['revenue'],
                'active_clients' => (int) $inputs['active_count'],
                'penalty' => (float) ($page['calculation']['kpi']['penalty'] ?? 0),
            ])
            ->assertOk();

        $this->assertEqualsWithDelta((float) $page['calculation']['total'], (float) $response->json('total'), 0.01);
        $this->assertEqualsWithDelta((float) $page['calculation']['total'], (float) $response->json('baseline_total'), 0.01);
    }

    #[Test]
    #[TestDox('Параметры, изменённые после расчёта, не сдвигают точку отсчёта калькулятора')]
    public function calculator_stays_on_the_parameters_of_the_snapshot(): void
    {
        $page = $this->actingAs($this->manager)->getJson('/crm/salary/data')->assertOk()->json();
        $snapshotTotal = (float) $page['calculation']['total'];
        $revenue = (float) $page['calculation']['inputs']['revenue'];
        $clients = (int) $page['calculation']['inputs']['active_count'];
        $penalty = (float) ($page['calculation']['kpi']['penalty'] ?? 0);

        // РОП урезал базу премии уже после того, как черновик посчитан (или месяц
        // утверждён и заморожен). Снимок на экране остаётся прежним — калькулятор
        // обязан считать теми же параметрами, иначе любое движение ползунка
        // показывало бы падение зарплаты, которого в сценарии нет.
        \App\Models\PayrollParamOverride::create([
            'personal_manager_id' => $this->profile->id,
            'period_month' => \App\Models\PayrollParamOverride::periodKey(null),
            'component_key' => 'kpi_bonus',
            'params' => ['base' => 10000],
        ]);

        $atFact = $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', [
                'month' => $this->month,
                'revenue' => $revenue,
                'active_clients' => $clients,
                'penalty' => $penalty,
            ])
            ->assertOk();

        $this->assertEqualsWithDelta($snapshotTotal, (float) $atFact->json('total'), 0.01);
        $this->assertEqualsWithDelta($snapshotTotal, (float) $atFact->json('baseline_total'), 0.01);

        // И сценарий «продали бы больше» обязан остаться плюсом к этой точке отсчёта.
        $better = $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', [
                'month' => $this->month,
                'revenue' => $revenue + 200_000,
                'active_clients' => $clients,
                'penalty' => $penalty,
            ])
            ->assertOk();

        $this->assertGreaterThan((float) $better->json('baseline_total'), (float) $better->json('total'));
        $this->assertEqualsWithDelta($snapshotTotal, (float) $better->json('baseline_total'), 0.01);
    }

    #[Test]
    #[TestDox('Больше выручки и клиентов — больше доход; больше штраф — меньше')]
    public function sliders_change_the_result(): void
    {
        $base = $this->simulate(400000, 1, 0);

        $this->assertGreaterThan($base, $this->simulate(1_000_000, 1, 0), 'выручка до плана');
        $this->assertGreaterThan($base, $this->simulate(400000, 2, 0), 'оба клиента купили');
        $this->assertLessThan($base, $this->simulate(400000, 1, 100000), 'штраф уменьшает');

        // Оклад остаётся даже при нуле по всем ползункам.
        $this->assertSame(70000.0, $this->simulate(0, 0, 0));
    }

    #[Test]
    #[TestDox('Потолок премии не превышается')]
    public function cap_is_respected(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', ['month' => $this->month, 'revenue' => 50_000_000, 'active_clients' => 2, 'penalty' => 0])
            ->assertOk();

        $this->assertTrue($response->json('capped'));
        $this->assertEqualsWithDelta(70000 + 170000, $response->json('total'), 0.01);
    }

    #[Test]
    #[TestDox('Симуляция требует права и валидирует вход')]
    public function guards(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', ['month' => $this->month])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['revenue', 'active_clients', 'penalty']);

        // Сотрудник CRM без права на зарплату — 403 (без доступа в CRM был бы редирект).
        $role = \Spatie\Permission\Models\Role::where('name', 'sales-manager')->where('guard_name', 'web')->firstOrFail();
        $role->revokePermissionTo('crm-salary.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', ['month' => $this->month, 'revenue' => 1, 'active_clients' => 0, 'penalty' => 0])
            ->assertForbidden();
    }

    private function simulate(float $revenue, int $clients, float $penalty): float
    {
        return (float) $this->actingAs($this->manager)
            ->postJson('/crm/salary/simulate', [
                'month' => $this->month,
                'revenue' => $revenue,
                'active_clients' => $clients,
                'penalty' => $penalty,
            ])
            ->assertOk()
            ->json('total');
    }
}
