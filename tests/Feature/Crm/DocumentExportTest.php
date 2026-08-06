<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * XLSX-выгрузка журналов документов.
 *
 * Файл обязан повторять то, что менеджер видит на экране: тот же скоуп, те же
 * фильтры, но без страниц. Выгрузка «всего журнала» вместо текущего отбора —
 * худший вид ошибки здесь: по ней считают и её отправляют клиенту.
 */
class DocumentExportTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $client;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config(['erp.organizations.enabled' => true]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->card = PersonalManager::factory()->create(['name' => 'Сухов']);
        $this->client = User::factory()->create([
            'name' => 'Личное имя',
            'erp_name' => 'ООО Клиент (рабочее)',
            'personal_manager_id' => $this->card->id,
        ]);
    }

    private function orderFor(?User $client = null, array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => ($client ?? $this->client)->id,
            'erp_created_at' => now(),
        ], $attributes));
    }

    private function shipmentFor(array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->client->id,
            'date' => now()->toDateString(),
            'erp_created_at' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 5000,
        ], $attributes));
    }

    /**
     * Читает выгруженный XLSX из стрима ответа.
     *
     * formatData = false: с форматированием PhpSpreadsheet отдаёт числа
     * строками, и проверить, что сумма ушла числом, стало бы нечем.
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
            $sheets[$sheet->getTitle()] = $sheet->toArray(null, true, false);
        }

        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        return $sheets;
    }

    #[Test]
    public function orders_export_returns_xlsx_with_document_columns(): void
    {
        $company = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'ООО Ромашка', 'tax_id' => '7701234567']);
        $warehouse = Warehouse::factory()->create(['name' => 'Москва основной']);

        $order = $this->orderFor(null, [
            'erp_number' => 'ЗК-100',
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'total_amount' => 1234.56,
            'currency_code' => 'RUB',
        ]);

        $response = $this->actingAs($this->head)->get(route('crm.orders.export'));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheets = $this->readXlsx($response->streamedContent());
        $this->assertArrayHasKey('Заказы', $sheets);

        [$headers, $row] = $sheets['Заказы'];

        $this->assertSame('Документ', $headers[0]);
        $this->assertSame('ЗК-100', $row[0]);
        $this->assertSame($order->number, $row[1]);
        // Рабочее наименование партнёра, а не личное имя клиента.
        $this->assertSame('ООО Клиент (рабочее)', $row[4]);
        $this->assertSame('Сухов', $row[6]);
        $this->assertSame('ООО Ромашка', $row[7]);
        $this->assertSame('7701234567', $row[8]);
        $this->assertSame('Москва основной', $row[10]);
        // Сумма — числом, иначе в Excel по колонке не посчитать итог.
        $this->assertSame(1234.56, $row[12]);
        $this->assertSame('RUB', $row[13]);
    }

    #[Test]
    public function export_respects_current_filters(): void
    {
        $warehouse = Warehouse::factory()->create(['name' => 'Москва основной']);

        $this->orderFor(null, ['erp_number' => 'ЗК-100', 'warehouse_id' => $warehouse->id]);
        $this->orderFor(null, ['erp_number' => 'ЗК-200']);

        $response = $this->actingAs($this->head)
            ->get(route('crm.orders.export', ['warehouse_ids' => [$warehouse->id]]));
        $response->assertOk();

        $rows = $this->readXlsx($response->streamedContent())['Заказы'];

        // Заголовок + одна отобранная строка.
        $this->assertCount(2, $rows);
        $this->assertSame('ЗК-100', $rows[1][0]);
    }

    /**
     * Выгрузка не должна обходить скоуп: страницу менеджер не видит, значит и
     * файл по ней получить нельзя.
     */
    #[Test]
    public function export_keeps_scope_of_regular_manager(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $own = User::factory()->create(['personal_manager_id' => $card->id]);

        $this->orderFor($own, ['erp_number' => 'ЗК-СВОЙ']);
        $this->orderFor(null, ['erp_number' => 'ЗК-ЧУЖОЙ']);

        $response = $this->actingAs($manager)->get(route('crm.orders.export'));
        $response->assertOk();

        $rows = $this->readXlsx($response->streamedContent())['Заказы'];

        $this->assertCount(2, $rows);
        $this->assertSame('ЗК-СВОЙ', $rows[1][0]);
    }

    /**
     * Экран режется страницами, файл — нет: 40 документов при per_page=15
     * должны уехать все.
     */
    #[Test]
    public function export_is_not_limited_by_page_size(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->orderFor(null, ['erp_number' => 'ЗК-'.$i]);
        }

        $response = $this->actingAs($this->head)->get(route('crm.orders.export', ['per_page' => 15]));
        $response->assertOk();

        $this->assertCount(41, $this->readXlsx($response->streamedContent())['Заказы']);
    }

    /**
     * При фильтре по товару вторым листом уходят сами позиции — иначе непонятно,
     * сколько именно этого товара в каждом документе.
     */
    #[Test]
    public function product_filter_adds_items_sheet(): void
    {
        $product = Product::factory()->create(['name' => 'Вибратор Neutral', 'sku' => 'SKU-1']);

        $order = $this->orderFor(null, ['erp_number' => 'ЗК-100']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Вибратор Neutral',
            'quantity' => 3,
            'price' => 500,
            'subtotal' => 1500,
        ]);

        $response = $this->actingAs($this->head)
            ->get(route('crm.orders.export', ['product_ids' => [$product->id]]));
        $response->assertOk();

        $sheets = $this->readXlsx($response->streamedContent());
        $this->assertArrayHasKey('Позиции', $sheets);

        [$headers, $row] = $sheets['Позиции'];

        $this->assertSame('Товар', $headers[3]);
        $this->assertSame('ЗК-100', $row[0]);
        $this->assertSame('Вибратор Neutral', $row[3]);
        $this->assertSame('SKU-1', $row[4]);
        $this->assertSame(3, $row[6]);
    }

    /**
     * Без фильтра по товару лист позиций не нужен: туда ушли бы все строки
     * всех документов журнала.
     */
    #[Test]
    public function items_sheet_is_absent_without_product_filter(): void
    {
        $product = Product::factory()->create();
        $order = $this->orderFor();
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);

        $response = $this->actingAs($this->head)->get(route('crm.orders.export'));
        $response->assertOk();

        $this->assertArrayNotHasKey('Позиции', $this->readXlsx($response->streamedContent()));
    }

    #[Test]
    public function shipments_export_returns_own_sheet_and_items(): void
    {
        $product = Product::factory()->create(['sku' => 'SKU-9']);

        $shipment = $this->shipmentFor(['erp_number' => 'РЕ-200']);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'product_name_snapshot' => 'Позиция из 1С',
            'quantity' => 2,
            'price' => 100,
            'total' => 200,
        ]);

        $response = $this->actingAs($this->head)
            ->get(route('crm.shipments.export', ['product_ids' => [$product->id]]));
        $response->assertOk();

        $sheets = $this->readXlsx($response->streamedContent());

        $this->assertArrayHasKey('Реализации', $sheets);
        $this->assertSame('РЕ-200', $sheets['Реализации'][1][0]);
        $this->assertSame('Позиция из 1С', $sheets['Позиции'][1][3]);
        $this->assertSame(200.0, $sheets['Позиции'][1][8]);
    }

    /**
     * Выключенный справочник юрлиц убирает колонку и из файла — иначе в выгрузке
     * висел бы пустой столбец, которого нет на экране.
     */
    #[Test]
    public function organization_column_follows_the_feature_flag(): void
    {
        config(['erp.organizations.enabled' => false]);

        $this->orderFor();

        $response = $this->actingAs($this->head)->get(route('crm.orders.export'));
        $response->assertOk();

        $headers = $this->readXlsx($response->streamedContent())['Заказы'][0];

        $this->assertNotContains('Организация', $headers);
        $this->assertContains('Склад', $headers);
    }
}
