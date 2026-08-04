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
use Tests\TestCase;

/**
 * Колонка «План / факт» в списке клиентов.
 *
 * Главный тест здесь — совпадение с ShipmentAnalyticsService. Второй движок
 * расчёта продаж запрещён принципом №1 роадмапа, и единственный способ это
 * удержать — сравнивать цифру колонки с цифрой отчёта в тесте.
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

    private function rows(User $actor, array $params = []): array
    {
        $response = $this->actingAs($actor)->get(route('crm.clients.index', $params));
        $response->assertOk();

        return collect($response->viewData('page')['props']['clients']['data'])->keyBy('id')->all();
    }

    #[Test]
    public function plan_fact_column_matches_shipment_analytics(): void
    {
        $client = $this->client();
        $this->shipment($client, 400000);
        $this->shipment($client, 212400);
        $this->plan($client, 800000);

        $rows = $this->rows($this->manager);
        $cell = $rows[$client->id]['plan_fact'];

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

        $cell = $this->rows($this->manager)[$client->id]['plan_fact'];

        $this->assertEqualsWithDelta(100000.0, $cell['fact'], 0.01);
        $this->assertSame(50, $cell['percent']);
    }

    #[Test]
    public function client_without_plan_has_null_plan_and_percent(): void
    {
        $client = $this->client();
        $this->shipment($client, 50000);

        $cell = $this->rows($this->manager)[$client->id]['plan_fact'];

        $this->assertNull($cell['plan']);
        $this->assertNull($cell['percent']);
        $this->assertEqualsWithDelta(50000.0, $cell['fact'], 0.01);
    }

    #[Test]
    public function plan_fact_hidden_without_crm_plans_view(): void
    {
        $client = $this->client();
        $this->plan($client, 100000);

        // Сотрудник с прямыми правами, но без crm-plans.view: колонка не должна
        // приезжать во фронт вовсе. Роль здесь не годится — sales-manager несёт
        // планы в наборе, и снять право у одного пользователя нельзя.
        $stripped = User::factory()->create();
        $stripped->givePermissionTo(['crm-clients.view', 'crm-tasks.view', 'crm-profile.view']);
        $card = PersonalManager::factory()->create(['user_id' => $stripped->id]);
        $client->update(['personal_manager_id' => $card->id]);

        $rows = $this->rows($stripped->fresh());

        $this->assertNull($rows[$client->id]['plan_fact']);
        $this->actingAs($stripped->fresh())
            ->get(route('crm.clients.index'))
            ->assertInertia(fn ($page) => $page->where('canSeePlans', false));
    }

    #[Test]
    public function plan_state_behind_selects_only_underperformers(): void
    {
        $behind = $this->client();
        $this->shipment($behind, 10000);
        $this->plan($behind, 100000);

        $ahead = $this->client();
        $this->shipment($ahead, 150000);
        $this->plan($ahead, 100000);

        $noPlan = $this->client();
        $this->shipment($noPlan, 1000);

        $rows = $this->rows($this->manager, ['plan_state' => 'behind']);

        $this->assertArrayHasKey($behind->id, $rows);
        $this->assertArrayNotHasKey($ahead->id, $rows);
        $this->assertArrayNotHasKey($noPlan->id, $rows);
    }

    #[Test]
    public function sorting_by_plan_percent_puts_clients_without_plan_last(): void
    {
        $low = $this->client();
        $this->shipment($low, 10000);
        $this->plan($low, 100000);

        $high = $this->client();
        $this->shipment($high, 90000);
        $this->plan($high, 100000);

        $noPlan = $this->client();

        $ids = array_keys($this->rows($this->manager, [
            'sort_by' => 'plan_percent',
            'sort_order' => 'asc',
        ]));

        $this->assertSame([$low->id, $high->id, $noPlan->id], $ids);
    }

    #[Test]
    public function manager_does_not_see_foreign_client_plan(): void
    {
        $foreignCard = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $foreignCard->id]);
        $this->shipment($foreign, 500000);
        $this->plan($foreign, 100000);

        $rows = $this->rows($this->manager);

        $this->assertArrayNotHasKey($foreign->id, $rows);
    }
}
