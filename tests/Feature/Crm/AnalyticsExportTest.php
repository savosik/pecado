<?php

namespace Tests\Feature\Crm;

use App\Models\Brand;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * XLSX-выгрузка отчёта продаж должна давать полную картину:
 * лимит топ-N применяется только к экрану, в файл уходят все строки разреза.
 */
class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-analytics.view', 'crm-department.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * РОП: видит весь отдел, значит и разрез по менеджерам.
     *
     * @return array{0: User, 1: PersonalManager}
     */
    private function makeHead(): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('crm-analytics.view');
        $actor->givePermissionTo(['crm-department.view', 'crm-clients-all.view']);
        $card = PersonalManager::create(['name' => 'РОП', 'user_id' => $actor->id]);

        return [$actor->fresh(), $card];
    }

    /**
     * Отгрузка клиента с одной позицией на каждый переданный товар.
     *
     * @param  array<int, Product>  $products
     */
    private function makeShipment(User $client, array $products): Shipment
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today(),
            'erp_created_at' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 0,
        ]);

        foreach ($products as $index => $product) {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100 + $index,
                'total' => 100 + $index,
                'subtotal' => 100 + $index,
                'product_name_snapshot' => $product->name,
                'brand_name_snapshot' => optional($product->brand)->name,
            ]);
        }

        return $shipment->fresh();
    }

    /**
     * Товары с уникальными брендами — по товару на бренд.
     *
     * @return array<int, Product>
     */
    private function makeProductsWithBrands(int $count): array
    {
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $brand = Brand::factory()->create(['name' => sprintf('Бренд %03d', $i)]);
            $products[] = Product::factory()->create([
                'brand_id' => $brand->id,
                'name' => sprintf('Товар %03d', $i),
            ]);
        }

        return $products;
    }

    /**
     * Читает выгруженный XLSX из стрима ответа.
     *
     * @return array<string, array<int, array<int, mixed>>> лист => строки
     */
    private function readXlsx(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $binary);

        $spreadsheet = (new XlsxReader)->load($path);
        $sheets = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheets[$sheet->getTitle()] = $sheet->toArray();
        }

        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        return $sheets;
    }

    #[Test]
    public function export_contains_all_rows_not_only_ui_top(): void
    {
        [$actor, $card] = $this->makeHead();
        $client = User::factory()->create(['personal_manager_id' => $card->id]);

        $count = ShipmentAnalyticsService::UI_LIMIT_DEFAULT + 5;
        $this->makeShipment($client, $this->makeProductsWithBrands($count));

        $response = $this->actingAs($actor)->get('/crm/analytics/export?'.http_build_query([
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]));
        $response->assertOk();

        $sheets = $this->readXlsx($response->streamedContent());

        $this->assertArrayHasKey('Итоги', $sheets);
        $this->assertArrayHasKey('Бренды', $sheets);
        $this->assertArrayHasKey('Товары', $sheets);
        $this->assertArrayHasKey('Менеджеры', $sheets);

        // Заголовок + строка на каждый бренд.
        $this->assertCount($count + 1, $sheets['Бренды']);
        $this->assertCount($count + 1, $sheets['Товары']);
    }

    #[Test]
    public function screen_marks_breakdown_as_truncated(): void
    {
        [$actor, $card] = $this->makeHead();
        $client = User::factory()->create(['personal_manager_id' => $card->id]);

        $count = ShipmentAnalyticsService::UI_LIMIT_DEFAULT + 5;
        $this->makeShipment($client, $this->makeProductsWithBrands($count));

        $payload = $this->actingAs($actor)->getJson('/crm/analytics/data?'.http_build_query([
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]))->assertOk()->json();

        $this->assertCount(ShipmentAnalyticsService::UI_LIMIT_DEFAULT, $payload['by_brand']);
        $this->assertTrue($payload['truncation']['by_brand']['truncated']);
        $this->assertSame(ShipmentAnalyticsService::UI_LIMIT_DEFAULT, $payload['truncation']['by_brand']['limit']);

        // Товаров меньше товарного потолка — обрезки нет.
        $this->assertFalse($payload['truncation']['by_product']['truncated']);
    }

    #[Test]
    public function service_returns_every_row_without_limit(): void
    {
        [, $card] = $this->makeHead();
        $client = User::factory()->create(['personal_manager_id' => $card->id]);

        $count = 12;
        $this->makeShipment($client, $this->makeProductsWithBrands($count));

        $service = app(ShipmentAnalyticsService::class);
        $ctx = AnalyticsContext::forScope([$client->id], AnalyticsContext::DATE_ERP, null);
        $filters = AnalyticsFilters::fromScopeRequest(request()->merge([
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
        ]));

        $this->assertCount($count, $service->byBrand($ctx, $filters, null));
        $this->assertCount(3, $service->byBrand($ctx, $filters, 3));
    }
}
