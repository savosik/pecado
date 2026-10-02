<?php

namespace Tests\Feature\Crm;

use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductExpectedArrival;
use App\Models\ProductExpectedArrivalSnapshot;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Ожидаемые поступления в CRM (v16.16.0, топик №16 Agent Hub).
 *
 * Две стороны одного решения заказчика от 01.10.2026: менеджер видит дату (или
 * «дата уточняется») и количество по складу, клиент не видит ничего — ни на
 * витрине, ни в клиентском API, ни зайдя по адресу раздела.
 */
class ExpectedArrivalsCrmTest extends TestCase
{
    use RefreshDatabase;

    /** Количество-метка: такого числа нет больше нигде, поэтому его удобно искать в ответах. */
    private const MARKER = 73913;

    private User $manager;

    private User $client;

    private Warehouse $main;

    private Warehouse $tyumen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);

        $this->main = Warehouse::factory()->create(['name' => 'Москва Основной']);
        $this->tyumen = Warehouse::factory()->create(['name' => 'Тюмень Основной']);
    }

    private function expect(Product $product, Warehouse $warehouse, ?int $dayOffset, float $quantity, string $source = 'purchase'): void
    {
        ProductExpectedArrival::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'expected_date' => $dayOffset === null ? null : Carbon::today()->addDays($dayOffset)->toDateString(),
            'quantity' => $quantity,
            'source' => $source,
        ]);
    }

    private function day(int $offset): string
    {
        return Carbon::today()->addDays($offset)->format('d.m.Y');
    }

    /** @return array<string, mixed> */
    private function props($response): array
    {
        return $response->viewData('page')['props'];
    }

    #[Test]
    #[TestDox('Менеджер видит по складам дату или «дата уточняется» и количество; ближайшее поступление — выше')]
    public function manager_sees_dates_and_quantities_by_warehouse(): void
    {
        $later = Product::factory()->create(['name' => 'Товар поздний', 'sku' => 'LATE-1']);
        $sooner = Product::factory()->create(['name' => 'Товар ранний', 'sku' => 'SOON-1', 'slug' => 'tovar-ranniy']);
        $pending = Product::factory()->create(['name' => 'Товар без даты', 'sku' => 'PEND-1']);
        Product::factory()->create(['name' => 'Товар без ожиданий']);

        $this->expect($later, $this->main, 20, 8);
        $this->expect($sooner, $this->main, 3, 20);
        $this->expect($sooner, $this->main, 12, 10);
        $this->expect($sooner, $this->tyumen, null, 5);
        $this->expect($pending, $this->main, null, 2);
        $sooner->warehouses()->attach($this->main->id, ['quantity' => 4]);

        ProductExpectedArrivalSnapshot::create([
            'product_id' => $sooner->id,
            'calculated_at' => now()->subHour(),
            'received_at' => Carbon::parse('2026-10-02 09:16:00'),
            'rows_count' => 3,
        ]);

        $response = $this->actingAs($this->manager)->get('/crm/arrivals');

        $response->assertOk();
        $props = $this->props($response);
        $rows = $props['rows']['data'];

        $this->assertSame(['Товар ранний', 'Товар поздний', 'Товар без даты'], array_column($rows, 'name'));

        $row = $rows[0];
        $this->assertSame('SOON-1', $row['sku']);
        $this->assertSame('tovar-ranniy', $row['slug']);
        $this->assertSame(['Москва Основной', 'Тюмень Основной'], array_column($row['warehouses'], 'warehouse'));
        $this->assertSame(
            [[$this->day(3), 20.0], [$this->day(12), 10.0]],
            array_map(static fn (array $line) => [$line['label'], (float) $line['quantity']], $row['warehouses'][0]['rows']),
        );
        $this->assertSame(4, $row['warehouses'][0]['free']);
        $this->assertSame('дата уточняется', $row['warehouses'][1]['rows'][0]['label']);
        $this->assertEquals(5, $row['warehouses'][1]['rows'][0]['quantity']);
        $this->assertSame(0, $row['warehouses'][1]['free']);
        $this->assertEquals(35, $row['summary']['quantity']);

        $this->assertSame('02.10.2026 09:16', $props['updatedAt']);
        $this->assertCount(2, $props['warehouses']);
    }

    #[Test]
    #[TestDox('Поиск по артикулу и названию, отбор по складу показывает только строки этого склада')]
    public function list_is_searchable_and_filterable_by_warehouse(): void
    {
        $first = Product::factory()->create(['name' => 'Лубрикант на водной основе', 'sku' => 'LUB-100']);
        $second = Product::factory()->create(['name' => 'Массажное масло', 'sku' => 'OIL-200']);

        $this->expect($first, $this->main, 5, 10);
        $this->expect($first, $this->tyumen, 7, 3);
        $this->expect($second, $this->main, 2, 6);

        $bySku = $this->props($this->actingAs($this->manager)->get('/crm/arrivals?search=LUB-1'));
        $this->assertSame(['Лубрикант на водной основе'], array_column($bySku['rows']['data'], 'name'));
        $this->assertSame('LUB-1', $bySku['filters']['search']);

        $byName = $this->props($this->actingAs($this->manager)->get('/crm/arrivals?search='.urlencode('масло')));
        $this->assertSame(['Массажное масло'], array_column($byName['rows']['data'], 'name'));

        $byWarehouse = $this->props($this->actingAs($this->manager)->get('/crm/arrivals?warehouse_id='.$this->tyumen->id));
        $this->assertSame(['Лубрикант на водной основе'], array_column($byWarehouse['rows']['data'], 'name'));
        $this->assertSame(['Тюмень Основной'], array_column($byWarehouse['rows']['data'][0]['warehouses'], 'warehouse'));
        $this->assertEquals(3, $byWarehouse['rows']['data'][0]['summary']['quantity']);
    }

    #[Test]
    #[TestDox('Дата, ставшая прошедшей, показывается как «дата уточняется», а не прячется')]
    public function past_date_is_shown_as_pending(): void
    {
        $product = Product::factory()->create();
        $this->expect($product, $this->main, -1, 9);

        $rows = $this->props($this->actingAs($this->manager)->get('/crm/arrivals'))['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('дата уточняется', $rows[0]['warehouses'][0]['rows'][0]['label']);
        $this->assertEquals(9, $rows[0]['warehouses'][0]['rows'][0]['quantity']);
    }

    #[Test]
    #[TestDox('Пустой раздел открывается: данных из 1С ещё не было')]
    public function empty_section_renders(): void
    {
        $props = $this->props($this->actingAs($this->manager)->get('/crm/arrivals')->assertOk());

        $this->assertSame([], $props['rows']['data']);
        $this->assertNull($props['updatedAt']);
    }

    #[Test]
    #[TestDox('В журнале недоборов рядом с отменённым товаром — когда его ждём')]
    public function shortage_journal_shows_expected_arrival_next_to_product(): void
    {
        $expected = Product::factory()->create();
        $notExpected = Product::factory()->create();
        $this->expect($expected, $this->main, 6, 15);
        $this->expect($expected, $this->tyumen, null, 5);

        foreach ([$expected, $notExpected] as $product) {
            $order = Order::factory()->create(['user_id' => $this->client->id]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'cancelled' => true,
                'cancelled_at' => now()->subDay(),
                'quantity' => 1,
                'final_price' => 100,
                'subtotal' => 100,
            ]);
        }

        $rows = collect($this->props($this->actingAs($this->manager)->get('/crm/shortages')->assertOk())['rows']['data'])
            ->keyBy('product_id');

        $this->assertSame($this->day(6), $rows[$expected->id]['expected']['label']);
        $this->assertEquals(20, $rows[$expected->id]['expected']['quantity']);
        $this->assertNull($rows[$notExpected->id]['expected']);
    }

    #[Test]
    #[TestDox('Раздел закрыт для клиента и гостя')]
    public function section_is_closed_for_clients_and_guests(): void
    {
        $this->get('/crm/arrivals')->assertRedirect('/login');
        $this->actingAs($this->client)->get('/crm/arrivals')->assertRedirect('/');
    }

    #[Test]
    #[TestDox('Клиент не видит ожиданий на витрине: карточка товара и каталог — ни гостю, ни клиенту')]
    public function storefront_does_not_expose_arrivals(): void
    {
        $product = Product::factory()->create(['slug' => 'tovar-s-ozhidaniem']);
        $this->expect($product, $this->main, 6, self::MARKER);

        $responses = [
            $this->withoutVite()->get('/products/tovar-s-ozhidaniem'),
            $this->withoutVite()->actingAs($this->client)->get('/products/tovar-s-ozhidaniem'),
            $this->withoutVite()->actingAs($this->client)->get('/products'),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertNoArrivals($response->getContent());
        }
    }

    #[Test]
    #[TestDox('Клиентский API не отдаёт ожиданий: карточка товара, остатки v1 и прежняя выгрузка остатков')]
    public function client_api_does_not_expose_arrivals(): void
    {
        $product = Product::factory()->create(['code' => 'ARR-1', 'sku' => 'SKU-ARR-1']);
        $this->expect($product, $this->main, 6, self::MARKER);

        $this->client->forceFill(['erp_id' => (string) Str::uuid()])->save();
        Company::factory()->create(['user_id' => $this->client->id, 'is_default' => true]);
        $token = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Агент клиента', 'is_active' => true]);
        $auth = ['Authorization' => 'Bearer '.$token->token];

        $responses = [
            $this->getJson('/api/client/v1/catalog/products/ARR-1', $auth),
            $this->getJson('/api/client/v1/catalog/stocks?identifiers[]=ARR-1', $auth),
            $this->getJson("/api/client-api/{$token->token}/stocks"),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertNoArrivals($response->getContent());
        }
    }

    #[Test]
    #[TestDox('Страж: с ожидаемыми поступлениями работает только код приёма шины и CRM')]
    public function arrivals_are_referenced_only_from_bus_ingest_and_crm(): void
    {
        $allowed = [
            'Http/Controllers/Crm/ExpectedArrivalController.php',
            'Http/Controllers/Crm/ShortageController.php',
            'Models/ProductExpectedArrival.php',
            'Models/ProductExpectedArrivalSnapshot.php',
            'Queue/Jobs/ErpIncomingJob.php',
            'Services/Erp/Handlers/HandleProductExpectedArrivalsUpdated.php',
            'Services/Stock/ExpectedArrivals.php',
        ];

        $found = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            // Классы домена и имя таблицы. Имена очереди и события (`erp_in.expected_arrivals`,
            // `product.expected_arrivals.updated`) под шаблон не попадают — это топология, а не данные.
            if (preg_match('/ExpectedArrival|product_expected_arrival/', $file->getContents()) === 1) {
                $found[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        sort($found);

        $this->assertSame(
            $allowed,
            $found,
            'Ожидаемые поступления видят только сотрудники (решение заказчика 01.10.2026). '
            .'Новый потребитель данных вне CRM — это вывод клиенту: сначала решение заказчика, потом код.',
        );
    }

    private function assertNoArrivals(string $content): void
    {
        $this->assertStringNotContainsString((string) self::MARKER, $content, 'Количество к поступлению утекло клиенту');
        $this->assertStringNotContainsStringIgnoringCase('expected_arrival', $content);
        $this->assertStringNotContainsStringIgnoringCase('expectedArrival', $content);
        $this->assertStringNotContainsString('дата уточняется', $content);
    }
}
