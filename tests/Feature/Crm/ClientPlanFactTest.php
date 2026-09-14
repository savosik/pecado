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
use App\Services\Crm\ClientPlanFactService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * План и факт партнёра за месяц — {@see ClientPlanFactService}.
 *
 * Из списка партнёров колонка убрана (планы на партнёра — рудимент), но сервис
 * читает зарплата. Главный тест здесь — совпадение с ShipmentAnalyticsService:
 * второй движок расчёта продаж запрещён принципом №1 роадмапа, и единственный
 * способ это удержать — сравнивать цифру сервиса с цифрой отчёта в тесте.
 */
class ClientPlanFactTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function client(): User
    {
        return User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    /**
     * Отгрузка текущего месяца по бизнес-дате 1С.
     */
    private function shipment(User $client, float $total, ?Carbon $date = null): Shipment
    {
        $date ??= Carbon::now()->startOfMonth()->addDays(2);

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

    private function plan(User $client, float $amount): CrmSalesPlan
    {
        return CrmSalesPlan::create([
            'period_month' => CrmSalesPlan::normalizeMonth(Carbon::now()),
            'target_type' => PlanTarget::CLIENT->value,
            'target_id' => $client->id,
            'amount' => $amount,
            'author_id' => $this->manager->id,
        ]);
    }

    /**
     * @return array{plan: float|null, fact: float, percent: int|null}
     */
    private function cell(User $client): array
    {
        return app(ClientPlanFactService::class)->forClients([$client->id], CarbonImmutable::now())[$client->id];
    }

    #[Test]
    public function plan_fact_matches_shipment_analytics(): void
    {
        $client = $this->client();
        $this->shipment($client, 400000);
        $this->shipment($client, 212400);
        $this->plan($client, 800000);

        $cell = $this->cell($client);

        // Эталон — тот же сервис, что считает /crm/analytics.
        $month = CarbonImmutable::now();
        $metrics = app(ShipmentAnalyticsService::class)->metrics(
            AnalyticsContext::forScope([$client->id], AnalyticsContext::DATE_ERP, null),
            new AnalyticsFilters(
                dateFrom: $month->startOfMonth()->startOfDay(),
                dateTo: $month->endOfMonth()->endOfDay(),
            ),
        );

        $this->assertEqualsWithDelta($metrics['total_amount'], $cell['fact'], 0.01);
        $this->assertEqualsWithDelta(612400.0, $cell['fact'], 0.01);
        $this->assertEqualsWithDelta(800000.0, $cell['plan'], 0.01);
        $this->assertSame(77, $cell['percent']);
    }

    #[Test]
    public function fact_ignores_shipments_outside_current_month(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000);
        // Прошлый месяц в текущую цифру попадать не должен.
        $this->shipment($client, 999999, Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDay());
        $this->plan($client, 200000);

        $cell = $this->cell($client);

        $this->assertEqualsWithDelta(100000.0, $cell['fact'], 0.01);
        $this->assertSame(50, $cell['percent']);
    }

    #[Test]
    public function client_without_plan_has_null_plan_and_percent(): void
    {
        $client = $this->client();
        $this->shipment($client, 50000);

        $cell = $this->cell($client);

        $this->assertNull($cell['plan']);
        $this->assertNull($cell['percent']);
        $this->assertEqualsWithDelta(50000.0, $cell['fact'], 0.01);
    }

    #[Test]
    public function client_list_no_longer_carries_plan_fact(): void
    {
        $client = $this->client();
        $this->shipment($client, 50000);
        $this->plan($client, 100000);

        $response = $this->actingAs($this->manager)->get(route('crm.clients.index', [
            'plan_state' => 'behind',
            'sort_by' => 'plan_percent',
        ]));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $rows = collect($props['clients']['data'])->keyBy('id')->all();

        // Партнёр в списке есть: неизвестный фильтр молча отбрасывается,
        // а строка не несёт ни плана, ни факта — планы на партнёра убраны.
        $this->assertArrayHasKey($client->id, $rows);
        $this->assertArrayNotHasKey('plan_fact', $rows[$client->id]);
        $this->assertArrayNotHasKey('plan_state', $props['filters']);
        $this->assertSame('id', $props['filters']['sort_by']);
        $this->assertArrayNotHasKey('canSeePlans', $props);
    }
}
