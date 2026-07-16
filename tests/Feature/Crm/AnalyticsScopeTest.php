<?php

namespace Tests\Feature\Crm;

use App\Models\Brand;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-dashboard.view', 'crm-analytics.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Создаёт сотрудника-менеджера: карточка PersonalManager + аккаунт с CRM-правами.
     */
    private function makeManagerActor(bool $head = false): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('crm-analytics.view');
        if ($head) {
            $actor->givePermissionTo('crm-clients-all.view');
        }
        $card = PersonalManager::create(['name' => $head ? 'РОП' : 'Менеджер', 'user_id' => $actor->id]);

        return [$actor->fresh(), $card];
    }

    private function makeClient(PersonalManager $card): User
    {
        return User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function makeShipmentFor(User $client, array $attrs = [], array $items = []): Shipment
    {
        $shipment = Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today(),
            'erp_created_at' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 0,
        ], $attrs));

        foreach ($items as $itemAttrs) {
            $product = $itemAttrs['product'] ?? Product::factory()->create();
            $qty = $itemAttrs['quantity'] ?? 1;
            $price = $itemAttrs['price'] ?? 100;
            $total = $itemAttrs['total'] ?? ($qty * $price);

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $price,
                'total' => $total,
                'subtotal' => $total,
                'product_name_snapshot' => $product->name,
                'brand_name_snapshot' => optional($product->brand)->name,
            ]);
        }

        return $shipment->fresh();
    }

    private function fetchData(User $actor, array $query = []): array
    {
        $url = '/crm/analytics/data'.($query !== [] ? '?'.http_build_query($query) : '');
        $response = $this->actingAs($actor)->getJson($url);
        $response->assertOk();

        return $response->json();
    }

    #[Test]
    public function manager_sees_only_own_clients_shipments(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $myClient = $this->makeClient($card);

        [, $otherCard] = $this->makeManagerActor();
        $foreignClient = $this->makeClient($otherCard);

        $this->makeShipmentFor($myClient, [], [['quantity' => 2, 'price' => 1000, 'total' => 2000]]);
        $this->makeShipmentFor($foreignClient, [], [['quantity' => 9, 'price' => 1000, 'total' => 9000]]);

        $payload = $this->fetchData($actor, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);

        $this->assertSame(1, $payload['metrics']['shipments_count']);
        $this->assertEquals(2000, $payload['metrics']['total_amount']);
    }

    #[Test]
    public function manager_cannot_inject_foreign_manager_id(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $myClient = $this->makeClient($card);

        [, $otherCard] = $this->makeManagerActor();
        $foreignClient = $this->makeClient($otherCard);

        $this->makeShipmentFor($myClient, [], [['quantity' => 1, 'price' => 500, 'total' => 500]]);
        $this->makeShipmentFor($foreignClient, [], [['quantity' => 1, 'price' => 700, 'total' => 700]]);

        // Менеджер пытается подставить чужой manager_id — должно игнорироваться.
        $payload = $this->fetchData($actor, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'manager_ids' => [$otherCard->id],
        ]);

        $this->assertSame(1, $payload['metrics']['shipments_count']);
        $this->assertEquals(500, $payload['metrics']['total_amount']);
        $this->assertSame([], $payload['by_manager']);
    }

    #[Test]
    public function head_sees_whole_department_and_manager_breakdown(): void
    {
        [$head] = $this->makeManagerActor(head: true);

        [, $cardA] = $this->makeManagerActor();
        [, $cardB] = $this->makeManagerActor();
        $clientA = $this->makeClient($cardA);
        $clientB = $this->makeClient($cardB);

        $this->makeShipmentFor($clientA, [], [['quantity' => 1, 'price' => 3000, 'total' => 3000]]);
        $this->makeShipmentFor($clientB, [], [['quantity' => 1, 'price' => 1000, 'total' => 1000]]);

        $payload = $this->fetchData($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);

        $this->assertSame(2, $payload['metrics']['shipments_count']);
        $this->assertEquals(4000, $payload['metrics']['total_amount']);

        $byManager = collect($payload['by_manager']);
        $this->assertNotNull($byManager->firstWhere('manager_id', $cardA->id));
        $this->assertEquals(3000, $byManager->firstWhere('manager_id', $cardA->id)['amount']);
        $this->assertEquals(1000, $byManager->firstWhere('manager_id', $cardB->id)['amount']);
    }

    #[Test]
    public function head_can_filter_by_manager(): void
    {
        [$head] = $this->makeManagerActor(head: true);

        [, $cardA] = $this->makeManagerActor();
        [, $cardB] = $this->makeManagerActor();
        $clientA = $this->makeClient($cardA);
        $clientB = $this->makeClient($cardB);

        $this->makeShipmentFor($clientA, [], [['quantity' => 1, 'price' => 3000, 'total' => 3000]]);
        $this->makeShipmentFor($clientB, [], [['quantity' => 1, 'price' => 1000, 'total' => 1000]]);

        $payload = $this->fetchData($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'manager_ids' => [$cardA->id],
        ]);

        $this->assertSame(1, $payload['metrics']['shipments_count']);
        $this->assertEquals(3000, $payload['metrics']['total_amount']);
    }

    #[Test]
    public function comparison_computes_previous_period(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makeShipmentFor($client, ['erp_created_at' => Carbon::today()], [['quantity' => 1, 'price' => 1000, 'total' => 1000]]);
        $this->makeShipmentFor($client, ['erp_created_at' => Carbon::yesterday()], [['quantity' => 1, 'price' => 400, 'total' => 400]]);

        $payload = $this->fetchData($actor, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'compare' => 1,
        ]);

        $this->assertEquals(1000, $payload['metrics']['total_amount']);
        $this->assertNotNull($payload['comparison']);
        $this->assertEquals(400, $payload['comparison']['metrics']['total_amount']);
        $this->assertEquals(600, $payload['comparison']['deltas']['total_amount']['abs']);
        $this->assertEquals(150.0, $payload['comparison']['deltas']['total_amount']['pct']);
    }

    #[Test]
    public function business_date_uses_erp_created_at_not_shipment_date(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        // Дата отгрузки — сегодня, но бизнес-дата документа 1С — 60 дней назад.
        $this->makeShipmentFor($client, [
            'date' => Carbon::today(),
            'erp_created_at' => Carbon::today()->subDays(60),
        ], [['quantity' => 1, 'price' => 999, 'total' => 999]]);

        $payload = $this->fetchData($actor, [
            'date_from' => Carbon::today()->subDays(7)->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);

        // По erp_created_at документ вне окна → 0 отгрузок.
        $this->assertSame(0, $payload['metrics']['shipments_count']);
    }

    #[Test]
    public function product_filter_limits_aggregates(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $brand = Brand::factory()->create(['name' => 'Alpha']);
        $p1 = Product::factory()->create(['brand_id' => $brand->id]);
        $p2 = Product::factory()->create(['brand_id' => $brand->id]);

        $this->makeShipmentFor($client, [], [
            ['product' => $p1, 'quantity' => 1, 'price' => 100, 'total' => 100],
            ['product' => $p2, 'quantity' => 1, 'price' => 900, 'total' => 900],
        ]);

        $payload = $this->fetchData($actor, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'product_ids' => [$p1->id],
        ]);

        $this->assertEquals(100, $payload['metrics']['total_amount']);
        $this->assertSame(1, $payload['metrics']['items_total_qty']);
    }

    #[Test]
    public function index_requires_analytics_permission(): void
    {
        // Есть доступ в CRM (crm-dashboard), но нет права на отчёты → 403 от гейта.
        $noAnalytics = User::factory()->create();
        $noAnalytics->givePermissionTo('crm-dashboard.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($noAnalytics)->get('/crm/analytics')->assertForbidden();

        [$actor] = $this->makeManagerActor();
        $response = $this->actingAs($actor)->get('/crm/analytics');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Crm/Pages/Analytics/Index')
            ->has('initial.metrics')
            ->where('seesAll', false)
        );
    }
}
