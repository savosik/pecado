<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\Brand;
use App\Models\CrmClientProfile;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Возможности: кому продать, кто не брал, кто просел (crm-07).
 *
 * Проверяем не «сервис что-то вернул», а три вещи, ради которых карточка делалась:
 * пресет отбирает того, кого обещал; порядок задаётся весами из конфига, а не
 * зашит в код; и у каждой строки есть причина попадания — список без объяснения
 * менеджер не примет.
 *
 * Время заморожено на 10 августа: давность закупок и падение против прошлого
 * месяца на живых часах были бы плавающими проверками.
 */
class OpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $head;

    private PersonalManager $foreignProfile;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

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

    private function client(?PersonalManager $profile = null, ?string $registeredAt = null): User
    {
        $client = User::factory()->create([
            'personal_manager_id' => ($profile ?? $this->managerProfile)->id,
        ]);

        if ($registeredAt !== null) {
            // created_at фабрика ставит «сейчас», а возраст аккаунта — сигнал
            // пресета «ни разу не покупали».
            $client->forceFill(['created_at' => Carbon::parse($registeredAt)])->saveQuietly();
        }

        return $client;
    }

    private function shipment(User $client, float $total, string $day, ?Brand $brand = null): Shipment
    {
        $date = Carbon::parse($day);

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);

        $product = Product::factory()->create($brand !== null ? ['brand_id' => $brand->id] : []);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'total' => $total,
            'subtotal' => $total,
        ]);

        return $shipment;
    }

    private function plan(PlanTarget $type, ?int $targetId, float $amount): void
    {
        CrmSalesPlan::create([
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
    private function list(User $actor, string $preset, array $params = []): array
    {
        $response = $this->actingAs($actor)->getJson(route('crm.opportunities.data', [
            'month' => $this->month,
            'preset' => $preset,
        ] + $params));

        $response->assertOk();

        return $response->json();
    }

    // --- Пресеты ------------------------------------------------------------

    #[Test]
    public function plan_lag_preset_lists_clients_short_of_their_plan(): void
    {
        $behind = $this->client();
        $this->shipment($behind, 100000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $behind->id, 1000000);

        $almost = $this->client();
        $this->shipment($almost, 95000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $almost->id, 100000);

        // План выполнен — догонять нечего.
        $done = $this->client();
        $this->shipment($done, 300000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $done->id, 200000);

        // Без плана в этот пресет клиент не попадает, даже если давно не покупал.
        $this->client();

        $rows = $this->list($this->manager, 'plan_lag')['rows'];

        $this->assertSame([$behind->id, $almost->id], array_column($rows, 'id'));
        $this->assertEqualsWithDelta(900000.0, $rows[0]['lag'], 0.01);
        $this->assertSame(10, $rows[0]['percent']);
    }

    #[Test]
    public function every_row_explains_why_the_client_is_here(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $client->id, 500000);

        $row = $this->list($this->manager, 'plan_lag')['rows'][0];

        $this->assertNotEmpty($row['reasons']);
        $this->assertSame(implode('; ', $row['reasons']), $row['explanation']);
        $this->assertStringContainsString('недобор плана', $row['explanation']);
        $this->assertStringContainsString('выполнено 20%', $row['explanation']);
    }

    #[Test]
    public function sleeping_preset_takes_overdue_cycle_and_check_above_median(): void
    {
        // Цикл 30 дней, последняя покупка 101 день назад — проспал три цикла.
        $sleeping = $this->client();
        $this->shipment($sleeping, 500000, '2026-05-01');
        CrmClientProfile::create(['user_id' => $sleeping->id, 'order_cycle_days' => 30]);

        // Покупал на прошлой неделе — не спящий.
        $active = $this->client();
        $this->shipment($active, 400000, '2026-08-03');

        // Спит, но чек мелкий — ниже медианы по базе.
        $small = $this->client();
        $this->shipment($small, 1000, '2026-05-01');

        $rows = $this->list($this->manager, 'sleeping')['rows'];

        $this->assertSame([$sleeping->id], array_column($rows, 'id'));
        $this->assertSame(101, $rows[0]['days_since']);
        $this->assertSame(30, $rows[0]['cycle_days']);
        $this->assertStringContainsString('при обычном цикле 30', $rows[0]['explanation']);
    }

    #[Test]
    public function declining_preset_compares_month_with_the_previous_one(): void
    {
        $dropped = $this->client();
        $this->shipment($dropped, 1000000, '2026-07-15');
        $this->shipment($dropped, 100000, '2026-08-05');

        // Тот же оборот, что и в июле, — не просел.
        $stable = $this->client();
        $this->shipment($stable, 300000, '2026-07-15');
        $this->shipment($stable, 300000, '2026-08-05');

        $rows = $this->list($this->manager, 'declining')['rows'];

        $this->assertSame([$dropped->id], array_column($rows, 'id'));
        $this->assertSame(90, $rows[0]['drop_percent']);
        $this->assertStringContainsString('просел на 90%', $rows[0]['explanation']);
    }

    #[Test]
    public function never_bought_preset_skips_recently_registered(): void
    {
        $old = $this->client(registeredAt: '2025-11-01');
        $fresh = $this->client(registeredAt: '2026-08-01');

        $buyer = $this->client(registeredAt: '2025-11-01');
        $this->shipment($buyer, 100000, '2026-08-05');

        $rows = $this->list($this->manager, 'never_bought')['rows'];

        $this->assertSame([$old->id], array_column($rows, 'id'));
        $this->assertNotContains($fresh->id, array_column($rows, 'id'));
        $this->assertStringContainsString('ни одной отгрузки', $rows[0]['explanation']);
    }

    #[Test]
    public function not_buying_preset_runs_through_gap_analysis(): void
    {
        $target = Brand::factory()->create(['name' => 'Целевой бренд']);
        $other = Brand::factory()->create(['name' => 'Другой бренд']);

        $buys = $this->client();
        $this->shipment($buys, 200000, '2026-07-10', $target);

        $doesNot = $this->client();
        $this->shipment($doesNot, 150000, '2026-07-10', $other);

        $payload = $this->list($this->manager, 'not_buying', [
            'dimension' => 'brand',
            'value' => $target->id,
        ]);

        $this->assertSame([$doesNot->id], array_column($payload['rows'], 'id'));
        $this->assertStringContainsString('не берёт: бренд «Целевой бренд»', $payload['rows'][0]['explanation']);
    }

    #[Test]
    public function not_buying_without_a_chosen_dimension_returns_nothing(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000, '2026-07-10');

        $payload = $this->list($this->manager, 'not_buying');

        $this->assertSame([], $payload['rows']);
        $this->assertTrue($payload['needs_dimension']);
    }

    // --- Ранжирование -------------------------------------------------------

    #[Test]
    public function weights_from_config_decide_the_order(): void
    {
        // Крупный недобор, мелкий чек.
        $bigLag = $this->client();
        $this->shipment($bigLag, 10000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $bigLag->id, 1000000);

        // Мелкий недобор, крупный чек.
        $bigCheck = $this->client();
        $this->shipment($bigCheck, 800000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $bigCheck->id, 850000);

        config()->set('crm.opportunities.weights', [
            'plan_gap' => 1.0, 'overdue' => 0, 'avg_check' => 0, 'drop' => 0, 'abc' => 0,
        ]);
        $byLag = array_column($this->list($this->manager, 'plan_lag')['rows'], 'id');

        config()->set('crm.opportunities.weights', [
            'plan_gap' => 0, 'overdue' => 0, 'avg_check' => 1.0, 'drop' => 0, 'abc' => 0,
        ]);
        $byCheck = array_column($this->list($this->manager, 'plan_lag')['rows'], 'id');

        $this->assertSame([$bigLag->id, $bigCheck->id], $byLag);
        $this->assertSame([$bigCheck->id, $bigLag->id], $byCheck);
    }

    #[Test]
    public function abc_class_comes_from_the_year_turnover(): void
    {
        $whale = $this->client();
        $this->shipment($whale, 5000000, '2026-03-10');
        $this->plan(PlanTarget::CLIENT, $whale->id, 6000000);

        $small = $this->client();
        $this->shipment($small, 20000, '2026-03-10');
        $this->plan(PlanTarget::CLIENT, $small->id, 100000);

        $rows = collect($this->list($this->manager, 'plan_lag')['rows'])->keyBy('id');

        $this->assertSame('A', $rows[$whale->id]['abc']);
        $this->assertSame('C', $rows[$small->id]['abc']);
        $this->assertStringContainsString('класс A по обороту за год', $rows[$whale->id]['explanation']);
    }

    // --- Скоуп и права ------------------------------------------------------

    #[Test]
    public function manager_sees_only_own_clients(): void
    {
        $own = $this->client();
        $this->shipment($own, 10000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $own->id, 500000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 10000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $foreign->id, 900000);

        // Даже прямо попросив отдел целиком и чужой скоуп, менеджер получает свой.
        $payload = $this->list($this->manager, 'plan_lag', [
            'scope' => 'manager',
            'scope_id' => $this->foreignProfile->id,
        ]);

        $this->assertSame([$own->id], array_column($payload['rows'], 'id'));
        $this->assertSame($this->managerProfile->id, $payload['scope']['id']);
    }

    #[Test]
    public function head_sees_the_department_and_can_switch_to_a_manager(): void
    {
        $mine = $this->client();
        $this->shipment($mine, 10000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $mine->id, 500000);

        $foreign = $this->client($this->foreignProfile);
        $this->shipment($foreign, 10000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $foreign->id, 900000);

        $department = $this->list($this->head, 'plan_lag');
        $this->assertEqualsCanonicalizing(
            [$mine->id, $foreign->id],
            array_column($department['rows'], 'id'),
        );

        $single = $this->list($this->head, 'plan_lag', [
            'scope' => 'manager',
            'scope_id' => $this->foreignProfile->id,
        ]);
        $this->assertSame([$foreign->id], array_column($single['rows'], 'id'));
    }

    #[Test]
    public function section_requires_its_own_permission(): void
    {
        // Сотрудник CRM, но без права на возможности: до /crm его пускает
        // middleware 'crm', а до раздела — нет.
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-clients.view');

        $this->actingAs($outsider->fresh())
            ->getJson(route('crm.opportunities.data', ['month' => $this->month]))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('crm.opportunities.index'))
            ->assertOk();
    }

    #[Test]
    public function export_streams_xlsx(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $client->id, 500000);

        $response = $this->actingAs($this->manager)->get(route('crm.opportunities.export', [
            'month' => $this->month,
            'preset' => 'plan_lag',
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    #[Test]
    public function dashboard_shows_the_hottest_rows_without_choosing_a_preset(): void
    {
        $client = $this->client();
        $this->shipment($client, 100000, '2026-08-05');
        $this->plan(PlanTarget::CLIENT, $client->id, 500000);

        $this->actingAs($this->manager)
            ->get(route('crm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'opportunities',
                fn ($rows) => collect($rows)->firstWhere('id', $client->id) !== null,
            ));
    }
}
