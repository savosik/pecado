<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\ContractorBalance;
use App\Models\CrmSalesPlan;
use App\Models\Payment;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Выполнение планов: сводка, прогноз, burndown и разрез по менеджерам (crm-06).
 *
 * Главный тест здесь — совпадение факта с `ShipmentAnalyticsService`. Второй движок
 * расчёта продаж запрещён принципом роадмапа, и единственный способ это удержать —
 * сравнивать цифру дашборда с цифрой отчёта прямо в тесте.
 *
 * Время заморожено на 10 августа: burndown и «нужно в день» зависят от того,
 * сколько дней месяца прошло, и на живых часах такие проверки были бы плавающими.
 */
class PlanProgressTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $head;

    private PersonalManager $foreignProfile;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        // 10-е число месяца из 31 дня: прошло 10 дней, осталось 21.
        $this->travelTo(Carbon::parse('2026-08-10 12:00:00'));

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        $this->foreignProfile = PersonalManager::factory()->create(['user_id' => $colleague->id]);

        $this->month = '2026-08';
    }

    private function client(?PersonalManager $profile = null): User
    {
        return User::factory()->create([
            'personal_manager_id' => ($profile ?? $this->managerProfile)->id,
        ]);
    }

    /**
     * Отгрузка по бизнес-дате 1С (`erp_created_at`) — той же, по которой считает
     * /crm/analytics.
     */
    private function shipment(User $client, float $total, ?string $day = null): Shipment
    {
        $date = Carbon::parse($day ?? '2026-08-05');

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $total,
            'total' => $total,
            'subtotal' => $total,
        ]);

        return $shipment;
    }

    private function plan(PlanTarget $type, ?int $targetId, float $amount): CrmSalesPlan
    {
        return CrmSalesPlan::create([
            'period_month' => CrmSalesPlan::normalizeMonth(Carbon::parse($this->month.'-01')),
            'target_type' => $type->value,
            'target_id' => CrmSalesPlan::targetKey($type, $targetId),
            'amount' => $amount,
            'author_id' => $this->head->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function progress(User $actor, array $params = []): array
    {
        $response = $this->actingAs($actor)
            ->getJson(route('crm.plans.progress', ['month' => $this->month] + $params));

        $response->assertOk();

        return $response->json();
    }

    /**
     * Эталон факта — тот же сервис, что считает /crm/analytics.
     *
     * @param  list<int>  $clientIds
     */
    private function analyticsFact(array $clientIds): float
    {
        $month = CarbonImmutable::parse($this->month.'-01');

        $metrics = app(ShipmentAnalyticsService::class)->metrics(
            AnalyticsContext::forScope($clientIds, AnalyticsContext::DATE_ERP, null),
            new AnalyticsFilters(
                dateFrom: $month->startOfMonth()->startOfDay(),
                dateTo: $month->endOfMonth()->endOfDay(),
            ),
        );

        return (float) $metrics['total_amount'];
    }

    #[Test]
    public function fact_matches_shipment_analytics_engine(): void
    {
        $first = $this->client();
        $second = $this->client();

        $this->shipment($first, 400000);
        $this->shipment($second, 212400);
        $this->plan(PlanTarget::MANAGER, $this->managerProfile->id, 1000000);

        $summary = $this->progress($this->manager)['summary'];

        $this->assertEqualsWithDelta(
            $this->analyticsFact([$first->id, $second->id]),
            $summary['fact'],
            0.01,
        );
        $this->assertEqualsWithDelta(612400.0, $summary['fact'], 0.01);
        $this->assertEqualsWithDelta(1000000.0, $summary['plan'], 0.01);
        $this->assertSame(61, $summary['percent']);
    }

    #[Test]
    public function forecast_and_pace_extrapolate_current_rate(): void
    {
        $client = $this->client();
        $this->shipment($client, 200000);
        $this->plan(PlanTarget::MANAGER, $this->managerProfile->id, 1000000);

        $summary = $this->progress($this->manager)['summary'];

        $this->assertSame(10, $summary['days_passed']);
        $this->assertSame(31, $summary['days_total']);
        $this->assertSame(21, $summary['days_left']);
        $this->assertEqualsWithDelta(800000.0, $summary['remaining'], 0.01);
        // 200 000 за 10 дней → 620 000 за 31 день.
        $this->assertEqualsWithDelta(620000.0, $summary['forecast'], 0.01);
        $this->assertEqualsWithDelta(800000 / 21, $summary['needed_per_day'], 0.01);
        // Ожидалось 1 000 000 × 10/31 ≈ 322 580, получено 200 000 — отстаём.
        $this->assertSame('behind', $summary['pace']);
    }

    #[Test]
    public function plan_absent_leaves_percent_and_pace_empty(): void
    {
        $client = $this->client();
        $this->shipment($client, 50000);

        $summary = $this->progress($this->manager)['summary'];

        $this->assertNull($summary['plan']);
        $this->assertNull($summary['percent']);
        $this->assertNull($summary['remaining']);
        $this->assertNull($summary['pace']);
        $this->assertEqualsWithDelta(50000.0, $summary['fact'], 0.01);
    }

    #[Test]
    public function burndown_draws_ideal_and_actual_without_future_days(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000, '2026-08-03');
        $this->shipment($client, 150000, '2026-08-07');
        $this->plan(PlanTarget::MANAGER, $this->managerProfile->id, 620000);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.plans.burndown', ['month' => $this->month]));

        $response->assertOk();
        $points = $response->json('points');

        // Сегодня 10-е: дней ровно десять, 11-е и дальше не дорисовываем.
        $this->assertCount(10, $points);
        $this->assertSame('2026-08-01', $points[0]['date']);
        $this->assertSame('2026-08-10', $points[9]['date']);

        // Идеальная линия — равномерное списание: за первый день сгорает 1/31 плана.
        $this->assertEqualsWithDelta(620000 * (1 - 1 / 31), $points[0]['ideal_remaining'], 0.01);
        $this->assertEqualsWithDelta(620000 * (1 - 10 / 31), $points[9]['ideal_remaining'], 0.01);

        // Фактическая — план минус накопленный факт: до 3-го числа не продано ничего.
        $this->assertEqualsWithDelta(620000.0, $points[1]['actual_remaining'], 0.01);
        $this->assertEqualsWithDelta(520000.0, $points[2]['actual_remaining'], 0.01);
        $this->assertEqualsWithDelta(370000.0, $points[6]['actual_remaining'], 0.01);
        $this->assertEqualsWithDelta(250000.0, $points[9]['fact_cumulative'], 0.01);
    }

    #[Test]
    public function burndown_without_plan_has_no_lines(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.plans.burndown', ['month' => $this->month]));

        $response->assertOk()->assertJsonPath('plan', null);
        $this->assertNull($response->json('points.0.ideal_remaining'));
        $this->assertEqualsWithDelta(100000.0, $response->json('points.9.fact_cumulative'), 0.01);
    }

    #[Test]
    public function manager_scope_excludes_foreign_clients(): void
    {
        $own = $this->client();
        $this->shipment($own, 100000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 999999);

        // Даже прямо попросив отдел целиком, менеджер получает свой скоуп:
        // подставленный параметр не должен открыть чужую выручку.
        $payload = $this->progress($this->manager, ['scope' => 'department']);

        $this->assertEqualsWithDelta(100000.0, $payload['summary']['fact'], 0.01);
        $this->assertSame('manager', $payload['scope']['type']);
        $this->assertSame($this->managerProfile->id, $payload['scope']['id']);
        $this->assertSame(1, $payload['scope']['clients_count']);
    }

    #[Test]
    public function manager_cannot_borrow_foreign_scope_by_id(): void
    {
        $own = $this->client();
        $this->shipment($own, 100000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 999999);

        $payload = $this->progress($this->manager, [
            'scope' => 'manager',
            'scope_id' => $this->foreignProfile->id,
        ]);

        $this->assertSame($this->managerProfile->id, $payload['scope']['id']);
        $this->assertEqualsWithDelta(100000.0, $payload['summary']['fact'], 0.01);
    }

    #[Test]
    public function head_sees_whole_department_and_can_switch_scope(): void
    {
        $own = $this->client();
        $this->shipment($own, 100000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 400000);

        $this->plan(PlanTarget::DEPARTMENT, null, 1000000);

        $department = $this->progress($this->head);

        $this->assertSame('department', $department['scope']['type']);
        $this->assertEqualsWithDelta(500000.0, $department['summary']['fact'], 0.01);
        $this->assertEqualsWithDelta(1000000.0, $department['summary']['plan'], 0.01);

        $single = $this->progress($this->head, [
            'scope' => 'manager',
            'scope_id' => $this->foreignProfile->id,
        ]);

        $this->assertEqualsWithDelta(400000.0, $single['summary']['fact'], 0.01);
    }

    #[Test]
    public function manager_cut_requires_permission_to_see_whole_department(): void
    {
        $this->actingAs($this->manager)
            ->getJson(route('crm.plans.by-manager', ['month' => $this->month]))
            ->assertForbidden();

        $this->actingAs($this->head)
            ->getJson(route('crm.plans.by-manager', ['month' => $this->month]))
            ->assertOk();
    }

    #[Test]
    public function manager_cut_lists_plan_fact_and_active_clients(): void
    {
        $own = $this->client();
        $this->shipment($own, 300000);
        $this->plan(PlanTarget::MANAGER, $this->managerProfile->id, 1000000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 100000);

        $rows = collect($this->actingAs($this->head)
            ->getJson(route('crm.plans.by-manager', ['month' => $this->month]))
            ->json('rows'))
            ->keyBy('manager_id');

        $mine = $rows[$this->managerProfile->id];

        $this->assertEqualsWithDelta(1000000.0, $mine['plan'], 0.01);
        $this->assertEqualsWithDelta(300000.0, $mine['fact'], 0.01);
        $this->assertSame(30, $mine['percent']);
        $this->assertSame(1, $mine['clients_count']);
        // 300 000 за 10 дней → 930 000 за 31 день.
        $this->assertEqualsWithDelta(930000.0, $mine['forecast'], 0.01);

        // Менеджер без плана, но с отгрузками, из отчёта не выпадает.
        $this->assertNull($rows[$this->foreignProfile->id]['plan']);
        $this->assertEqualsWithDelta(100000.0, $rows[$this->foreignProfile->id]['fact'], 0.01);
    }

    #[Test]
    public function client_scope_shows_plan_and_burndown_of_one_client(): void
    {
        $target = $this->client();
        $this->shipment($target, 120000, '2026-08-04');
        $this->plan(PlanTarget::CLIENT, $target->id, 300000);

        // Второй клиент того же менеджера в цифру провала попасть не должен.
        $other = $this->client();
        $this->shipment($other, 500000);

        $params = ['scope' => 'client', 'scope_id' => $target->id];
        $summary = $this->progress($this->manager, $params)['summary'];

        $this->assertEqualsWithDelta(300000.0, $summary['plan'], 0.01);
        $this->assertEqualsWithDelta(120000.0, $summary['fact'], 0.01);
        $this->assertSame(40, $summary['percent']);

        $points = $this->actingAs($this->manager)
            ->getJson(route('crm.plans.burndown', ['month' => $this->month] + $params))
            ->assertOk()
            ->json('points');

        $this->assertCount(10, $points);
        // До 4-го числа не отгружено ничего, дальше остаток падает на 120 000.
        $this->assertEqualsWithDelta(300000.0, $points[2]['actual_remaining'], 0.01);
        $this->assertEqualsWithDelta(180000.0, $points[3]['actual_remaining'], 0.01);
    }

    #[Test]
    public function foreign_client_scope_leaks_nothing(): void
    {
        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 999999);
        $this->plan(PlanTarget::CLIENT, $foreign->id, 800000);

        $payload = $this->progress($this->manager, [
            'scope' => 'client',
            'scope_id' => $foreign->id,
        ]);

        $this->assertSame(0, $payload['scope']['clients_count']);
        $this->assertEqualsWithDelta(0.0, $payload['summary']['fact'], 0.01);
        $this->assertNull($payload['summary']['plan']);
    }

    #[Test]
    public function clients_table_is_sorted_by_lag(): void
    {
        $behind = $this->client();
        $this->shipment($behind, 10000);
        $this->plan(PlanTarget::CLIENT, $behind->id, 500000);

        $almost = $this->client();
        $this->shipment($almost, 90000);
        $this->plan(PlanTarget::CLIENT, $almost->id, 100000);

        $noPlan = $this->client();
        $this->shipment($noPlan, 70000);

        // Ни плана, ни отгрузок — в отчёте о выполнении такому клиенту не место.
        $this->client();

        $rows = $this->progress($this->manager)['clients'];

        $this->assertSame(
            [$behind->id, $almost->id, $noPlan->id],
            array_column($rows, 'id'),
        );
        $this->assertEqualsWithDelta(490000.0, $rows[0]['lag'], 0.01);
        $this->assertNull($rows[2]['lag']);
    }

    /**
     * Кто ведёт партнёра — прямо в строке: в отделе целиком по названию юрлица
     * не понять, чей это партнёр, а список отстающих адресуется менеджеру.
     */
    #[Test]
    public function clients_rows_carry_owning_manager(): void
    {
        $own = $this->client();
        $this->shipment($own, 100000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 50000);

        $rows = collect($this->progress($this->head)['clients'])->keyBy('id');

        $this->assertSame($this->managerProfile->id, $rows[$own->id]['manager']['id']);
        $this->assertSame($this->managerProfile->name, $rows[$own->id]['manager']['name']);
        $this->assertSame($this->foreignProfile->name, $rows[$foreign->id]['manager']['name']);
    }

    /**
     * Долг, просрочка и последний платёж в строке партнёра — те же источники,
     * что в /crm/finance: суммы `contractor_balances` и свежайшее входящее
     * поступление из `payments`. Возврат клиенту платежом не считается.
     */
    #[Test]
    public function clients_rows_carry_debt_and_last_payment(): void
    {
        $client = $this->client();
        $this->plan(PlanTarget::CLIENT, $client->id, 100000);
        $this->shipment($client, 40000);

        // Два контрагента одного партнёра — долг и просрочка складываются.
        ContractorBalance::create([
            'user_id' => $client->id,
            'contractor_uuid' => (string) Str::uuid(),
            'tax_id' => '7700000001',
            'current_balance' => 150000,
            'overdue_debt' => 30000,
        ]);
        ContractorBalance::create([
            'user_id' => $client->id,
            'contractor_uuid' => (string) Str::uuid(),
            'tax_id' => '7700000002',
            'current_balance' => 50000,
            'overdue_debt' => 0,
        ]);

        Payment::factory()->create(['user_id' => $client->id, 'date' => '2026-08-03 10:00:00', 'amount' => 25000]);
        Payment::factory()->create(['user_id' => $client->id, 'date' => '2026-08-08 12:00:00', 'amount' => 40000]);
        // Возврат позже поступления — последним платежом быть не должен.
        Payment::factory()->outgoing()->create(['user_id' => $client->id, 'date' => '2026-08-09 09:00:00', 'amount' => 5000]);

        $row = collect($this->progress($this->manager)['clients'])->firstWhere('id', $client->id);

        $this->assertEqualsWithDelta(200000.0, $row['finance']['debt'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $row['finance']['overdue_debt'], 0.01);
        $this->assertSame('08.08.2026', $row['finance']['last_payment']['date']);
        $this->assertEqualsWithDelta(40000.0, $row['finance']['last_payment']['amount'], 0.01);
        // Сегодня заморожено 10 августа — платёж 8-го был два дня назад.
        $this->assertSame(2, $row['finance']['last_payment']['days_ago']);
    }

    /**
     * Партнёр без строки баланса (1С ещё не прислала) — нули, а не отсутствие
     * ячейки; без платежей — last_payment = null.
     */
    #[Test]
    public function partner_without_balance_gets_zero_debt_not_missing_cell(): void
    {
        $client = $this->client();
        $this->plan(PlanTarget::CLIENT, $client->id, 100000);

        $row = collect($this->progress($this->manager)['clients'])->firstWhere('id', $client->id);

        $this->assertEqualsWithDelta(0.0, $row['finance']['debt'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['finance']['overdue_debt'], 0.001);
        $this->assertNull($row['finance']['last_payment']);
    }

    /**
     * Долг — финансовые данные: без `crm-finance.view` колонка не приходит
     * вовсе (`finance: null`), а не приходит нулями.
     */
    #[Test]
    public function finance_cell_requires_finance_permission(): void
    {
        $client = $this->client();
        $this->plan(PlanTarget::CLIENT, $client->id, 100000);

        ContractorBalance::create([
            'user_id' => $client->id,
            'contractor_uuid' => (string) Str::uuid(),
            'tax_id' => '7700000003',
            'current_balance' => 150000,
            'overdue_debt' => 30000,
        ]);

        Role::findByName('sales-manager')->revokePermissionTo('crm-finance.view');

        $row = collect($this->progress($this->manager->fresh())['clients'])->firstWhere('id', $client->id);

        $this->assertNull($row['finance']);
    }

    #[Test]
    public function export_streams_xlsx(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000);
        $this->plan(PlanTarget::MANAGER, $this->managerProfile->id, 500000);

        $response = $this->actingAs($this->manager)
            ->get(route('crm.plans.export', ['month' => $this->month]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    /**
     * Команда прогрева проходит весь путь расчёта под первым пользователем
     * с правом на отдел: те же сервисы и те же ключи кэша, что у страницы.
     */
    #[Test]
    public function warm_command_precomputes_progress_caches(): void
    {
        $client = $this->client();
        $this->shipment($client, 50000);
        $this->plan(PlanTarget::DEPARTMENT, null, 1000000);

        $this->artisan('crm:plans-warm')
            ->expectsOutputToContain('Прогрето скоупов')
            ->assertSuccessful();
    }

    #[Test]
    public function progress_requires_plans_view_permission(): void
    {
        // Сотрудник CRM, но без права на планы: до /crm его пускает middleware
        // 'crm', а до выполнения планов — нет.
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-clients.view');

        $this->actingAs($outsider->fresh())
            ->getJson(route('crm.plans.progress', ['month' => $this->month]))
            ->assertForbidden();
    }
}
