<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
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

        // Знаменатель — рабочие дни производственного календаря (mot-41): в августе 2026
        // их 21, к 10-му числу (понедельник) прошло 6 — 3–7 августа и сам 10-й.
        $this->assertSame(6, $summary['days_passed']);
        $this->assertSame(21, $summary['days_total']);
        $this->assertSame(15, $summary['days_left']);
        $this->assertEqualsWithDelta(800000.0, $summary['remaining'], 0.01);
        // 200 000 за 6 рабочих дней → 700 000 за 21.
        $this->assertEqualsWithDelta(700000.0, $summary['forecast'], 0.01);
        $this->assertEqualsWithDelta(800000 / 15, $summary['needed_per_day'], 0.01);
        // Ожидалось 1 000 000 × 6/21 ≈ 285 714, получено 200 000 — отстаём.
        $this->assertSame('behind', $summary['pace']);
        // То же — на сегодняшнее число: сколько нужно было к этому дню, какой это
        // процент и какой темп в день идёт на самом деле.
        $this->assertEqualsWithDelta(285714.29, $summary['plan_to_date'], 0.01);
        $this->assertSame(70, $summary['percent_to_date']);
        $this->assertEqualsWithDelta(85714.29, $summary['remaining_to_date'], 0.01);
        $this->assertEqualsWithDelta(200000 / 6, $summary['current_per_day'], 0.01);
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

        // Идеальная линия списывается по рабочим дням: 1 августа 2026 — суббота, план
        // ещё не «горит»; к 10-му прошло 6 рабочих дней из 21.
        $this->assertEqualsWithDelta(620000.0, $points[0]['ideal_remaining'], 0.01);
        $this->assertEqualsWithDelta(620000 * (1 - 6 / 21), $points[9]['ideal_remaining'], 0.01);

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
        // 300 000 за 6 рабочих дней → 1 050 000 за 21 рабочий день августа.
        $this->assertEqualsWithDelta(1050000.0, $mine['forecast'], 0.01);

        // Менеджер без плана, но с отгрузками, из отчёта не выпадает.
        $this->assertNull($rows[$this->foreignProfile->id]['plan']);
        $this->assertEqualsWithDelta(100000.0, $rows[$this->foreignProfile->id]['fact'], 0.01);
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
