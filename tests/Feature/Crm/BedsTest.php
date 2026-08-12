<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Crm\PlanScopeResolver;
use App\Services\Crm\SalesPlanService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * «Грядки» (crm-08): полотно плана периода.
 *
 * Главное свойство раздела — он ничего не считает сам. Цифра на плитке обязана
 * совпадать с цифрой «Планов продаж» для того же клиента, иначе картинка начнёт
 * жить своей жизнью и однажды покажет закрытый план там, где его нет.
 */
class BedsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $other;

    private User $clientWithPlan;

    private User $clientSleeping;

    private User $foreignClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();
        Cache::flush();

        $this->travelTo('2026-08-10 12:00:00');

        $this->manager = User::factory()->create(['name' => 'Менеджер А']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Сухов']);

        $this->other = User::factory()->create(['name' => 'Менеджер Б']);
        $this->other->assignRole('sales-manager');
        $foreignProfile = PersonalManager::factory()->create(['user_id' => $this->other->id, 'name' => 'Петров']);

        $this->clientWithPlan = User::factory()->create([
            'name' => 'Клиент с планом',
            'personal_manager_id' => $profile->id,
        ]);
        $this->clientSleeping = User::factory()->create([
            'name' => 'Клиент спящий',
            'personal_manager_id' => $profile->id,
        ]);
        $this->foreignClient = User::factory()->create([
            'name' => 'Чужой клиент',
            'personal_manager_id' => $foreignProfile->id,
        ]);

        // План клиента на август и половина факта: 100 000 плана, 40 000 отгружено.
        CrmSalesPlan::create([
            'period_month' => '2026-08-01',
            'target_type' => PlanTarget::CLIENT->value,
            'target_id' => $this->clientWithPlan->id,
            'amount' => 100000,
        ]);
        $this->shipment($this->clientWithPlan, '2026-08-05', 40000);

        // Спящий: покупал в апреле, с тех пор тишина — при цикле по умолчанию 45 дней.
        $this->shipment($this->clientSleeping, '2026-04-10', 60000);

        // Чужой клиент с планом и фактом — он не должен попасть в полотно менеджера А.
        CrmSalesPlan::create([
            'period_month' => '2026-08-01',
            'target_type' => PlanTarget::CLIENT->value,
            'target_id' => $this->foreignClient->id,
            'amount' => 500000,
        ]);
        $this->shipment($this->foreignClient, '2026-08-06', 500000);
    }

    private function shipment(User $client, string $date, float $amount): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $client->id,
            'date' => $date,
            'erp_created_at' => $date.' 10:00:00',
            'total_amount' => $amount,
        ]);

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'quantity' => 1,
            'total' => $amount,
        ]);
    }

    private function canvas(User $actor, array $params = [])
    {
        return $this->actingAs($actor)->getJson(
            route('crm.beds.data', $params + ['month' => '2026-08'])
        );
    }

    #[Test]
    #[TestDox('Раздел закрыт без права crm-beds.view')]
    public function the_section_is_gated(): void
    {
        // Сотрудник с доступом в CRM, но без права на раздел: роль не выдаём,
        // право даём точечно — иначе crm-beds.view приехало бы вместе с ролью.
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-clients.view');

        $this->actingAs($outsider)->get(route('crm.beds.index'))->assertForbidden();

        $this->actingAs($this->manager)->get(route('crm.beds.index'))->assertOk();
    }

    #[Test]
    #[TestDox('Менеджер видит только своих клиентов')]
    public function the_canvas_is_limited_to_the_actor_scope(): void
    {
        $names = collect($this->canvas($this->manager)->assertOk()->json('tiles'))->pluck('name');

        $this->assertContains('Клиент с планом', $names->all());
        $this->assertNotContains('Чужой клиент', $names->all());
    }

    #[Test]
    #[TestDox('Выполнение на плитке совпадает с расчётом планов')]
    public function tile_percent_matches_the_plan_service(): void
    {
        $tile = collect($this->canvas($this->manager)->assertOk()->json('tiles'))
            ->firstWhere('name', 'Клиент с планом');

        $this->assertSame(100000.0, (float) $tile['plan']);
        $this->assertSame(40000.0, (float) $tile['fact']);
        $this->assertSame(40, $tile['percent']);
        $this->assertSame(60000.0, (float) $tile['lag']);

        // Та же цифра, что отдаёт разбивка «Планов продаж» — витрина не считает сама.
        $scope = app(PlanScopeResolver::class)->resolve($this->manager, null, null);
        $month = app(SalesPlanService::class)->parseMonth('2026-08');
        $fromPlans = collect(app(\App\Services\Crm\PlanProgressService::class)
            ->clients($month, $scope, $this->manager))
            ->firstWhere('id', $this->clientWithPlan->id);

        $this->assertSame($fromPlans['percent'], $tile['percent']);
        $this->assertSame((float) $fromPlans['fact'], (float) $tile['fact']);
    }

    #[Test]
    #[TestDox('Площадь без плана берётся от оборота, а не от нуля')]
    public function area_without_a_plan_comes_from_turnover(): void
    {
        $tile = collect($this->canvas($this->manager)->assertOk()->json('tiles'))
            ->firstWhere('name', 'Клиент спящий');

        $this->assertNull($tile['plan']);
        $this->assertSame('potential', $tile['area_source']);
        // 60 000 за год → средний месяц 5 000.
        $this->assertSame(5000.0, (float) $tile['area']);
    }

    #[Test]
    #[TestDox('Спящий клиент помечен: не покупает дольше своего цикла')]
    public function sleeping_clients_are_marked(): void
    {
        $tiles = collect($this->canvas($this->manager)->assertOk()->json('tiles'));

        $this->assertTrue($tiles->firstWhere('name', 'Клиент спящий')['sleeping']);
        $this->assertFalse($tiles->firstWhere('name', 'Клиент с планом')['sleeping']);
    }

    #[Test]
    #[TestDox('Клиенты без плана и без истории плиток не получают')]
    public function clients_without_plan_and_history_are_not_drawn(): void
    {
        $silent = User::factory()->create([
            'name' => 'Никогда не покупал',
            'personal_manager_id' => $this->manager->managerProfile->id,
        ]);

        $names = collect($this->canvas($this->manager)->assertOk()->json('tiles'))->pluck('name');

        $this->assertNotContains('Никогда не покупал', $names->all());
        $this->assertDatabaseHas('users', ['id' => $silent->id]);
    }

    #[Test]
    #[TestDox('Нераспределённый остаток плана виден')]
    public function the_unallocated_remainder_is_visible(): void
    {
        // План менеджера — 300 000, по клиентам разложено 100 000.
        CrmSalesPlan::create([
            'period_month' => '2026-08-01',
            'target_type' => PlanTarget::MANAGER->value,
            'target_id' => $this->manager->managerProfile->id,
            'amount' => 300000,
        ]);

        $payload = $this->canvas($this->manager)->assertOk();

        $this->assertSame(100000.0, (float) $payload->json('allocated'));
        $this->assertSame(200000.0, (float) $payload->json('unallocated'));
    }

    #[Test]
    #[TestDox('РОП по умолчанию видит отдел плитками менеджеров и проваливается в одного')]
    public function the_head_starts_with_managers_and_drills_into_one(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        CrmSalesPlan::create([
            'period_month' => '2026-08-01',
            'target_type' => PlanTarget::MANAGER->value,
            'target_id' => $this->manager->managerProfile->id,
            'amount' => 300000,
        ]);

        $department = $this->canvas($head)->assertOk();
        $this->assertSame('managers', $department->json('mode'));
        $this->assertContains('Сухов', collect($department->json('tiles'))->pluck('name')->all());

        $drilled = $this->canvas($head, [
            'scope' => 'manager',
            'scope_id' => $this->manager->managerProfile->id,
        ])->assertOk();

        $this->assertSame('clients', $drilled->json('mode'));
        $this->assertContains(
            'Клиент с планом',
            collect($drilled->json('tiles'))->pluck('name')->all(),
        );
    }

    #[Test]
    #[TestDox('Провал в клиента отдаёт аналитику и сигналы, чужой — 404')]
    public function the_drilldown_returns_analytics_and_signals(): void
    {
        $payload = $this->actingAs($this->manager)
            ->getJson(route('crm.beds.details', $this->clientWithPlan->id).'?month=2026-08')
            ->assertOk();

        $payload->assertJsonPath('client.id', $this->clientWithPlan->id);
        $payload->assertJsonStructure([
            'metrics', 'timeline' => ['points'], 'brands', 'categories', 'products', 'documents',
        ]);
        $this->assertSame(40000.0, (float) $payload->json('signals.fact'));

        $this->actingAs($this->manager)
            ->getJson(route('crm.beds.details', $this->foreignClient->id))
            ->assertNotFound();
    }
}
