<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\CrmClientProfile;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Crm\ClientFunnelService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Воронка партнёров над списком: счётчики стадий, среднемесячные отгрузки,
 * догрузка страниц и граница видимости.
 *
 * Главное здесь — совпадение: число на чипе равно числу строк по тому же
 * фильтру, а деньги совпадают с ShipmentAnalyticsService. Второй движок
 * счёта в этом разделе не заводится.
 */
class ClientFunnelTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $card;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
    }

    private function client(?PersonalManager $card = null, ?ClientLifecycleStatus $stage = null): User
    {
        $client = User::factory()->create(['personal_manager_id' => ($card ?? $this->card)->id]);

        if ($stage !== null) {
            CrmClientProfile::factory()->create([
                'user_id' => $client->id,
                'lifecycle_status' => $stage,
            ]);
        }

        return $client;
    }

    /**
     * Отгрузка по бизнес-дате 1С; по умолчанию — три месяца назад, внутри окна.
     */
    private function shipment(User $client, float $total, ?Carbon $date = null): Shipment
    {
        $date ??= Carbon::now()->subMonthsNoOverflow(3)->startOfMonth()->addDays(2);

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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stages(User $actor, array $params = []): array
    {
        $response = $this->actingAs($actor)->get(route('crm.clients.index', $params));
        $response->assertOk();

        $funnel = $response->viewData('page')['props']['funnel'];

        return collect($funnel['stages'])->keyBy('value')->all();
    }

    #[Test]
    public function funnel_counts_every_stage_and_reads_missing_profile_as_active(): void
    {
        $this->client(stage: ClientLifecycleStatus::LEAD);
        $this->client(stage: ClientLifecycleStatus::SLEEPING);
        $this->client(stage: ClientLifecycleStatus::SLEEPING);
        $this->client(stage: ClientLifecycleStatus::BANKRUPT);
        $this->client(); // без профиля — активен, как в колонке и в фильтре

        $stages = $this->stages($this->manager);

        $this->assertSame(1, $stages['lead']['count']);
        $this->assertSame(1, $stages['active']['count']);
        $this->assertSame(2, $stages['sleeping']['count']);
        $this->assertSame(1, $stages['bankrupt']['count']);
        $this->assertSame(0, $stages['competitor']['count']);

        // Порядок чипов — лестница перечисления: от лида до ушедшего.
        $this->assertSame(
            array_map(fn (ClientLifecycleStatus $s) => $s->value, ClientLifecycleStatus::cases()),
            array_keys($stages),
        );
        $this->assertSame('green', $stages['active']['color']);
    }

    #[Test]
    public function stage_chip_ignores_the_stage_filter_but_respects_the_rest(): void
    {
        $this->client(stage: ClientLifecycleStatus::SLEEPING);
        $active = $this->client();
        $this->client();

        // Выбран чип «Активные» — число спящих не должно исчезнуть: чипы это
        // оси воронки, а не ещё один фильтр.
        $stages = $this->stages($this->manager, ['lifecycle' => 'active']);

        $this->assertSame(1, $stages['sleeping']['count']);
        $this->assertSame(2, $stages['active']['count']);

        // А отбор по поиску воронку сужает — она читается по той же базе,
        // что и таблица под ней.
        $active->update(['name' => 'Ромашка']);
        $stages = $this->stages($this->manager, ['search' => 'Ромашка']);

        $this->assertSame(1, $stages['active']['count']);
        $this->assertSame(0, $stages['sleeping']['count']);
    }

    #[Test]
    public function funnel_total_equals_list_total_under_the_same_filter(): void
    {
        $this->client(stage: ClientLifecycleStatus::LEAD);
        $this->client();
        $this->client();

        $response = $this->actingAs($this->manager)->get(route('crm.clients.index'));
        $props = $response->viewData('page')['props'];

        $this->assertSame(3, $props['funnel']['total']);
        $this->assertSame($props['clients']['total'], $props['funnel']['total']);
    }

    #[Test]
    public function monthly_amount_is_twelve_month_revenue_divided_by_twelve(): void
    {
        $first = $this->client(stage: ClientLifecycleStatus::SLEEPING);
        $second = $this->client(stage: ClientLifecycleStatus::SLEEPING);
        $this->shipment($first, 120000);
        $this->shipment($first, 60000, Carbon::now()->startOfMonth()->addDay());
        $this->shipment($second, 240000);
        // Тринадцать месяцев назад — за окном, в сумму не входит.
        $this->shipment($second, 999999, Carbon::now()->subMonthsNoOverflow(13)->startOfMonth()->addDay());

        $stages = $this->stages($this->manager);

        // Эталон — тот же сервис, что считает /crm/analytics, за то же окно.
        $now = CarbonImmutable::now();
        $metrics = app(ShipmentAnalyticsService::class)->metrics(
            AnalyticsContext::forScope([$first->id, $second->id], AnalyticsContext::DATE_ERP, null),
            new AnalyticsFilters(
                dateFrom: $now->subMonthsNoOverflow(ClientFunnelService::MONTHS - 1)->startOfMonth()->startOfDay(),
                dateTo: $now->endOfDay(),
            ),
        );

        $this->assertEqualsWithDelta(420000.0, $metrics['total_amount'], 0.01);
        $this->assertEqualsWithDelta(
            $metrics['total_amount'] / ClientFunnelService::MONTHS,
            $stages['sleeping']['monthly_amount'],
            0.01,
        );
        $this->assertEqualsWithDelta(35000.0, $stages['sleeping']['monthly_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $stages['active']['monthly_amount'], 0.01);
    }

    #[Test]
    public function money_is_hidden_without_crm_plans_view(): void
    {
        $client = $this->client(stage: ClientLifecycleStatus::ACTIVE);
        $this->shipment($client, 120000);

        // Прямые права без планов: счётчики есть, денег нет — та же граница,
        // что у колонки «План / факт».
        $stripped = User::factory()->create();
        $stripped->givePermissionTo(['crm-clients.view', 'crm-tasks.view', 'crm-profile.view']);
        $card = PersonalManager::factory()->create(['user_id' => $stripped->id]);
        $client->update(['personal_manager_id' => $card->id]);

        $stages = $this->stages($stripped->fresh());

        $this->assertSame(1, $stages['active']['count']);
        $this->assertNull($stages['active']['monthly_amount']);
    }

    #[Test]
    public function funnel_is_absent_without_crm_profile_view(): void
    {
        $stripped = User::factory()->create();
        $stripped->givePermissionTo(['crm-clients.view']);
        PersonalManager::factory()->create(['user_id' => $stripped->id]);

        $this->actingAs($stripped->fresh())
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('funnel', null));
    }

    #[Test]
    public function head_funnel_narrows_by_manager_filter(): void
    {
        $otherManager = User::factory()->create();
        $otherManager->assignRole('sales-manager');
        $otherCard = PersonalManager::factory()->create(['user_id' => $otherManager->id]);

        $this->client(stage: ClientLifecycleStatus::LEAD);
        $this->client($otherCard, ClientLifecycleStatus::LEAD);
        $this->client($otherCard, ClientLifecycleStatus::LEAD);

        $all = $this->stages($this->head, ['scope' => 'department']);
        $mine = $this->stages($this->head, ['scope' => 'department', 'manager_id' => $this->card->id]);

        $this->assertSame(3, $all['lead']['count']);
        $this->assertSame(1, $mine['lead']['count']);
    }

    #[Test]
    public function manager_funnel_never_counts_foreign_clients(): void
    {
        $foreignManager = User::factory()->create();
        $foreignManager->assignRole('sales-manager');
        $foreignCard = PersonalManager::factory()->create(['user_id' => $foreignManager->id]);

        $this->client(stage: ClientLifecycleStatus::LEAD);
        $this->client($foreignCard, ClientLifecycleStatus::LEAD);

        $stages = $this->stages($this->manager, ['scope' => 'department']);

        $this->assertSame(1, $stages['lead']['count']);
    }

    #[Test]
    public function list_defaults_to_a_hundred_rows_per_page(): void
    {
        $this->client();

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.per_page', 100)
                ->where('clients.per_page', 100));
    }

    #[Test]
    public function data_endpoint_pages_the_same_selection_as_the_index(): void
    {
        foreach (range(1, 7) as $i) {
            $this->client();
        }

        $index = $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['per_page' => 5]))
            ->viewData('page')['props']['clients'];

        $second = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.data', ['per_page' => 5, 'page' => 2]))
            ->assertOk()
            ->json();

        $this->assertSame(7, $second['total']);
        $this->assertSame(2, $second['current_page']);
        $this->assertCount(2, $second['data']);

        // Страницы не пересекаются: догруженная строка не дублирует первую.
        $firstIds = array_column($index['data'], 'id');
        $secondIds = array_column($second['data'], 'id');

        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertArrayHasKey('lifecycle', $second['data'][0]);
    }

    #[Test]
    public function data_endpoint_does_not_leak_foreign_clients(): void
    {
        $foreignManager = User::factory()->create();
        $foreignManager->assignRole('sales-manager');
        $foreignCard = PersonalManager::factory()->create(['user_id' => $foreignManager->id]);

        $mine = $this->client();
        $foreign = $this->client($foreignCard);

        $ids = collect($this->actingAs($this->manager)
            ->getJson(route('crm.clients.data', ['scope' => 'department', 'manager_id' => $foreignCard->id]))
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function data_endpoint_requires_crm_clients_view(): void
    {
        // Сотрудник CRM без права на партнёров: не редирект «в кабинет», а 403.
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-tasks.view');

        $this->actingAs($outsider)
            ->getJson(route('crm.clients.data'))
            ->assertForbidden();
    }
}
