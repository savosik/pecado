<?php

namespace Tests\Feature\User\Analytics;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Region;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $rub = Currency::create([
            'code' => 'RUB',
            'symbol' => '₽',
            'name' => 'Рубль',
            'is_base' => true,
            'exchange_rate' => 1,
        ]);

        $region = Region::factory()->create(['currency_id' => $rub->id]);

        $this->user = User::factory()->create(['region_id' => $region->id]);
    }

    private function makeShipment(array $shipmentAttrs = [], array $items = []): Shipment
    {
        $shipment = Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'date' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 0,
        ], $shipmentAttrs));

        $sum = 0;
        foreach ($items as $itemAttrs) {
            $product = $itemAttrs['product'] ?? Product::factory()->create();
            $qty = $itemAttrs['quantity'] ?? 1;
            $price = $itemAttrs['price'] ?? 100;
            $total = $itemAttrs['total'] ?? ($qty * $price);
            $sum += $total;

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $price,
                'total' => $total,
                'subtotal' => $total,
                'product_name_snapshot' => $itemAttrs['product_name_snapshot'] ?? $product->name,
                'brand_name_snapshot' => $itemAttrs['brand_name_snapshot']
                    ?? optional($product->brand)->name,
            ]);
        }

        $shipment->update(['total_amount' => $sum]);

        return $shipment->fresh();
    }

    private function fetchData(array $query = []): array
    {
        $url = '/cabinet/analytics/data'.($query !== [] ? '?'.http_build_query($query) : '');
        $response = $this->actingAs($this->user)->getJson($url);
        $response->assertOk();

        return $response->json();
    }

    #[Test]
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/cabinet/analytics')->assertRedirect('/login');
    }

    #[Test]
    public function index_renders_analytics_inertia_page(): void
    {
        $response = $this->actingAs($this->user)->get('/cabinet/analytics');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/Cabinet/Analytics/Index')
            ->has('initial.metrics')
            ->has('filterOptions.companies')
            ->has('filterOptions.brands')
            ->has('filterOptions.categories')
        );
    }

    #[Test]
    public function metrics_aggregate_only_current_user_shipments(): void
    {
        $brand = Brand::factory()->create(['name' => 'Acme']);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        $this->makeShipment(
            ['date' => Carbon::today()],
            [['product' => $product, 'quantity' => 3, 'price' => 1000, 'total' => 3000]]
        );

        // Чужой пользователь — его отгрузки не должны попасть в метрики
        $other = User::factory()->create();
        Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $other->id,
            'date' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 99999,
        ]);

        $payload = $this->fetchData([
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);

        $this->assertSame(1, $payload['metrics']['shipments_count']);
        $this->assertEquals(3000, $payload['metrics']['total_amount']);
        $this->assertSame(3, $payload['metrics']['items_total_qty']);
    }

    #[Test]
    public function by_brand_groups_using_snapshot_field(): void
    {
        $brand1 = Brand::factory()->create(['name' => 'Alpha']);
        $brand2 = Brand::factory()->create(['name' => 'Beta']);
        $p1 = Product::factory()->create(['brand_id' => $brand1->id]);
        $p2 = Product::factory()->create(['brand_id' => $brand2->id]);

        $this->makeShipment([], [
            ['product' => $p1, 'quantity' => 2, 'price' => 500, 'total' => 1000],
            ['product' => $p2, 'quantity' => 1, 'price' => 300, 'total' => 300],
        ]);
        $this->makeShipment([], [
            ['product' => $p1, 'quantity' => 5, 'price' => 500, 'total' => 2500],
        ]);

        $payload = $this->fetchData();
        $byBrand = collect($payload['by_brand']);

        $alpha = $byBrand->firstWhere('label', 'Alpha');
        $beta = $byBrand->firstWhere('label', 'Beta');

        $this->assertNotNull($alpha);
        $this->assertEquals(3500, $alpha['amount']);
        $this->assertSame(7, $alpha['qty']);
        $this->assertSame(2, $alpha['shipments_count']);

        $this->assertNotNull($beta);
        $this->assertEquals(300, $beta['amount']);
    }

    #[Test]
    public function by_category_builds_full_path_via_nested_set(): void
    {
        $root = Category::create(['name' => 'Гели и смазки', 'slug' => 'gels']);
        $child = Category::create(['name' => 'Лубриканты', 'slug' => 'lub', 'parent_id' => $root->id]);

        $product = Product::factory()->create(['category_id' => $child->id]);
        $orphan = Product::factory()->create(['category_id' => null]);

        $this->makeShipment([], [
            ['product' => $product, 'quantity' => 1, 'price' => 200, 'total' => 200],
            ['product' => $orphan, 'quantity' => 1, 'price' => 50, 'total' => 50],
        ]);

        $payload = $this->fetchData();
        $byCategory = collect($payload['by_category']);

        $this->assertNotNull($byCategory->firstWhere('label', 'Гели и смазки / Лубриканты'));
        $this->assertNotNull($byCategory->firstWhere('label', 'Без категории'));
    }

    #[Test]
    public function by_contractor_groups_by_company_with_tax_id_fallback(): void
    {
        $company = Company::factory()->russia()->create([
            'user_id' => $this->user->id,
            'name' => 'ООО Ромашка',
        ]);

        $this->makeShipment(
            ['company_id' => $company->id, 'tax_id' => $company->tax_id],
            [['quantity' => 1, 'price' => 1000, 'total' => 1000]]
        );
        $this->makeShipment(
            ['company_id' => null, 'tax_id' => '7777777777'],
            [['quantity' => 1, 'price' => 500, 'total' => 500]]
        );

        $payload = $this->fetchData();
        $byContractor = collect($payload['by_contractor']);

        $this->assertNotNull($byContractor->firstWhere('label', 'ООО Ромашка'));
        $this->assertNotNull($byContractor->firstWhere('label', 'ИНН 7777777777 (не привязано)'));
    }

    #[Test]
    public function date_filter_excludes_shipments_outside_range(): void
    {
        $product = Product::factory()->create();

        $this->makeShipment(
            ['date' => Carbon::today()->subDays(60)],
            [['product' => $product, 'quantity' => 10, 'price' => 100, 'total' => 1000]]
        );
        $this->makeShipment(
            ['date' => Carbon::today()],
            [['product' => $product, 'quantity' => 1, 'price' => 100, 'total' => 100]]
        );

        $payload = $this->fetchData([
            'date_from' => Carbon::today()->subDays(7)->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);

        $this->assertSame(1, $payload['metrics']['shipments_count']);
        $this->assertEquals(100, $payload['metrics']['total_amount']);
    }

    #[Test]
    public function default_period_is_current_month(): void
    {
        $product = Product::factory()->create();

        $this->makeShipment(
            ['date' => Carbon::today()->startOfMonth()->subDay()],
            [['product' => $product, 'quantity' => 1, 'price' => 100, 'total' => 100]]
        );
        $this->makeShipment(
            ['date' => Carbon::today()->startOfMonth()],
            [['product' => $product, 'quantity' => 2, 'price' => 100, 'total' => 200]]
        );

        // Без явных date_from/date_to — должно попасть только то, что в текущем месяце
        $payload = $this->fetchData();

        $this->assertSame(1, $payload['metrics']['shipments_count']);
        $this->assertEquals(200, $payload['metrics']['total_amount']);
    }

    #[Test]
    public function time_series_bucket_switches_with_period_width(): void
    {
        $product = Product::factory()->create();
        $this->makeShipment(
            ['date' => Carbon::today()],
            [['product' => $product, 'quantity' => 1, 'price' => 10, 'total' => 10]]
        );

        $dayResp = $this->fetchData([
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);
        $this->assertSame('day', $dayResp['time_series']['bucket']);

        $weekResp = $this->fetchData([
            'date_from' => Carbon::today()->subDays(120)->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);
        $this->assertSame('week', $weekResp['time_series']['bucket']);

        $monthResp = $this->fetchData([
            'date_from' => Carbon::today()->subYear()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]);
        $this->assertSame('month', $monthResp['time_series']['bucket']);
    }

    #[Test]
    public function time_series_fills_gaps_with_zero_days_across_full_range(): void
    {
        $product = Product::factory()->create();
        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-10');

        // Отгрузки только в двух днях диапазона — между ними и по краям пусто.
        $this->makeShipment(
            ['date' => Carbon::parse('2026-06-03')],
            [['product' => $product, 'quantity' => 1, 'price' => 100, 'total' => 100]]
        );
        $this->makeShipment(
            ['date' => Carbon::parse('2026-06-07')],
            [['product' => $product, 'quantity' => 2, 'price' => 100, 'total' => 200]]
        );

        $resp = $this->fetchData([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ]);

        $points = $resp['time_series']['points'];

        // Ряд покрывает каждый день диапазона (10 дней), а не только дни с данными.
        $this->assertCount(10, $points);
        $this->assertSame('2026-06-01', $points[0]['period']);
        $this->assertSame('2026-06-10', $points[9]['period']);

        $byPeriod = collect($points)->keyBy('period');
        $this->assertEqualsWithDelta(100, $byPeriod['2026-06-03']['amount'], 0.01);
        $this->assertEqualsWithDelta(200, $byPeriod['2026-06-07']['amount'], 0.01);
        // День без отгрузок — честный ноль, а не пропуск.
        $this->assertSame(0.0, (float) $byPeriod['2026-06-05']['amount']);
        $this->assertSame(0, (int) $byPeriod['2026-06-05']['qty']);
    }
}
