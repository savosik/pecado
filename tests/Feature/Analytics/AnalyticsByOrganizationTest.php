<?php

namespace Tests\Feature\Analytics;

use App\Models\Company;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Разрезы и фильтры аналитики по нашим организациям (v15.8.0, карточка org-09).
 *
 * Главное требование — группа «Организация не указана» из разреза не исчезает:
 * без неё итог разреза не сойдётся с общим, а это классический источник недоверия
 * к отчёту.
 */
class AnalyticsByOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->product = Product::factory()->create();
    }

    private function shipment(?Organization $organization, float $total, ?Warehouse $warehouse = null): Shipment
    {
        $shipment = Shipment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $organization?->id,
            'warehouse_id' => $warehouse?->id,
            'number' => '29УТ-'.fake()->unique()->numerify('######'),
            'date' => '2026-07-15',
            'erp_created_at' => '2026-07-15 10:00:00',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => $total,
            'total' => $total,
            'subtotal' => $total,
        ]);

        return $shipment;
    }

    private function context(): AnalyticsContext
    {
        return new AnalyticsContext(
            userIds: [$this->client->id],
            dateColumn: 'shipments.date',
        );
    }

    private function filters(array $query = []): AnalyticsFilters
    {
        return AnalyticsFilters::fromScopeRequest(new Request(array_merge([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], $query)));
    }

    private function service(): ShipmentAnalyticsService
    {
        return app(ShipmentAnalyticsService::class);
    }

    // ──────────────────────────────────────────────
    // Разрез
    // ──────────────────────────────────────────────

    #[Test]
    public function breakdown_keeps_unassigned_group_so_total_matches(): void
    {
        $orgA = Organization::factory()->create(['name' => 'ООО Пекадо']);

        $this->shipment($orgA, 10000);
        $this->shipment(null, 4000);

        $rows = $this->service()->byOrganization($this->context(), $this->filters());

        $this->assertCount(2, $rows);

        $unassigned = $rows->firstWhere('is_unassigned', true);
        $this->assertNotNull($unassigned, 'Группа «не указана» обязана присутствовать в разрезе');
        $this->assertSame('Организация не указана', $unassigned['label']);
        $this->assertEqualsWithDelta(4000, $unassigned['amount'], 0.01);

        $this->assertEqualsWithDelta(
            14000,
            $rows->sum('amount'),
            0.01,
            'Итог разреза должен сходиться с общей выручкой',
        );
    }

    #[Test]
    public function stub_organization_is_marked_in_breakdown(): void
    {
        $stub = Organization::factory()->stub()->create();
        $this->shipment($stub, 5000);

        $row = $this->service()->byOrganization($this->context(), $this->filters())->first();

        $this->assertTrue($row['is_stub']);
    }

    #[Test]
    public function warehouse_breakdown_works(): void
    {
        $warehouse = Warehouse::factory()->create(['name' => 'Москва основной']);
        $this->shipment(null, 3000, $warehouse);
        $this->shipment(null, 1000);

        $rows = $this->service()->byWarehouse($this->context(), $this->filters());

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(3000, $rows->firstWhere('label', 'Москва основной')['amount'], 0.01);
        $this->assertEqualsWithDelta(1000, $rows->firstWhere('is_unassigned', true)['amount'], 0.01);
    }

    // ──────────────────────────────────────────────
    // Фильтры
    // ──────────────────────────────────────────────

    #[Test]
    public function filter_by_organization_narrows_metrics(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->shipment($orgA, 10000);
        $this->shipment($orgB, 4000);

        $metrics = $this->service()->metrics(
            $this->context(),
            $this->filters(['organization_ids' => [$orgA->id]]),
        );

        $this->assertEqualsWithDelta(10000, $metrics['total_amount'], 0.01);
    }

    /**
     * Маркер «не указана» — рабочий выбор переходного периода.
     */
    #[Test]
    public function filter_can_select_only_unassigned_documents(): void
    {
        $orgA = Organization::factory()->create();
        $this->shipment($orgA, 10000);
        $this->shipment(null, 4000);

        $metrics = $this->service()->metrics(
            $this->context(),
            $this->filters(['organization_ids' => [AnalyticsFilters::UNASSIGNED]]),
        );

        $this->assertEqualsWithDelta(4000, $metrics['total_amount'], 0.01);
    }

    #[Test]
    public function filter_combines_organization_and_unassigned(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->shipment($orgA, 10000);
        $this->shipment($orgB, 7000);
        $this->shipment(null, 4000);

        $metrics = $this->service()->metrics(
            $this->context(),
            $this->filters(['organization_ids' => [$orgA->id, AnalyticsFilters::UNASSIGNED]]),
        );

        $this->assertEqualsWithDelta(14000, $metrics['total_amount'], 0.01);
    }

    /**
     * Исключение «Рекламы» с образцами — основной сценарий, ради которого
     * исключения вообще нужны.
     */
    #[Test]
    public function excluded_organization_is_removed_from_calculation(): void
    {
        $main = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $promo = Organization::factory()->create(['name' => 'Реклама']);

        $this->shipment($main, 10000);
        $this->shipment($promo, 500);

        $metrics = $this->service()->metrics(
            $this->context(),
            $this->filters(['exclude_organization_ids' => [$promo->id]]),
        );

        $this->assertEqualsWithDelta(10000, $metrics['total_amount'], 0.01);
    }

    /**
     * Исключение не должно заодно выкидывать исторические документы:
     * у них нечего исключать.
     */
    #[Test]
    public function exclusion_keeps_documents_without_organization(): void
    {
        $promo = Organization::factory()->create();

        $this->shipment($promo, 500);
        $this->shipment(null, 4000);

        $metrics = $this->service()->metrics(
            $this->context(),
            $this->filters(['exclude_organization_ids' => [$promo->id]]),
        );

        $this->assertEqualsWithDelta(4000, $metrics['total_amount'], 0.01);
    }

    #[Test]
    public function filter_by_warehouse_narrows_metrics(): void
    {
        $warehouse = Warehouse::factory()->create();
        $this->shipment(null, 3000, $warehouse);
        $this->shipment(null, 1000);

        $metrics = $this->service()->metrics(
            $this->context(),
            $this->filters(['warehouse_ids' => [$warehouse->id]]),
        );

        $this->assertEqualsWithDelta(3000, $metrics['total_amount'], 0.01);
    }

    // ──────────────────────────────────────────────
    // Опции фильтров
    // ──────────────────────────────────────────────

    #[Test]
    public function filter_options_list_only_organizations_present_in_scope(): void
    {
        $used = Organization::factory()->create(['name' => 'ООО Пекадо']);
        Organization::factory()->create(['name' => 'Не участвовала в отгрузках']);

        $this->shipment($used, 1000);

        $options = $this->service()->filterOptionsForScope($this->context());

        $this->assertCount(1, $options['organizations']);
        $this->assertSame($used->id, $options['organizations'][0]['id']);
    }
}
