<?php

namespace Tests\Feature\Crm;

use App\Models\Category;
use App\Models\Company;
use App\Models\CrmClientProfile;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Дозаполнение анкет партнёров по их же документам (`crm:enrich-profiles`).
 *
 * Защищается ровно одно свойство: команда добавляет недостающее и никогда не
 * переписывает заполненное. Город владеет 1С, периодичность и интересы —
 * менеджер; молчаливая перезапись чужого вывода своей арифметикой отучает от
 * поля быстрее, чем пустое поле.
 */
class CrmEnrichProfilesTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-12 12:00:00');

        $manager = PersonalManager::factory()->create(['name' => 'Сухов']);

        $this->client = User::factory()->create([
            'name' => 'Иванов Иван',
            'erp_name' => 'Иванов Иван Иванович ИП, г.Тюмень',
            'city' => null,
            'personal_manager_id' => $manager->id,
        ]);
    }

    #[Test]
    #[TestDox('Сухой прогон ничего не записывает')]
    public function dry_run_writes_nothing(): void
    {
        $this->artisan('crm:enrich-profiles')->assertSuccessful();

        $this->assertNull($this->client->fresh()->city);
    }

    #[Test]
    #[TestDox('Город берётся из рабочего наименования партнёра')]
    public function it_fills_city_from_working_name(): void
    {
        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertSame('Тюмень', $this->client->fresh()->city);
    }

    #[Test]
    #[TestDox('Заполненный город остаётся как есть — им владеет 1С')]
    public function it_keeps_existing_city(): void
    {
        $this->client->update(['city' => 'Ялта']);

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertSame('Ялта', $this->client->fresh()->city);
    }

    #[Test]
    #[TestDox('Без города в документах поле остаётся пустым')]
    public function it_leaves_city_empty_when_documents_are_silent(): void
    {
        $this->client->update(['erp_name' => 'Иванов Иван Иванович ИП']);
        Company::factory()->create([
            'user_id' => $this->client->id,
            'legal_address' => 'ул. Плеханова д.9, стр.10',
            'actual_address' => null,
        ]);

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertNull($this->client->fresh()->city);
    }

    #[Test]
    #[TestDox('Город берётся из адреса контрагента, когда в наименовании его нет')]
    public function it_falls_back_to_contractor_address(): void
    {
        $this->client->update(['erp_name' => 'ООО «Софтленд»', 'name' => 'ООО Софтленд']);
        Company::factory()->create([
            'user_id' => $this->client->id,
            'actual_address' => '664047, Иркутская обл, г Иркутск, ул Пискунова, д. 54',
        ]);

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertSame('Иркутск', $this->client->fresh()->city);
    }

    #[Test]
    #[TestDox('Периодичность — медиана интервалов между днями заказов')]
    public function it_fills_order_cycle_from_median_interval(): void
    {
        // Интервалы 10, 10 и 40 дней: среднее дало бы 20, медиана — 10.
        foreach (['2026-05-01', '2026-05-11', '2026-05-21', '2026-06-30'] as $date) {
            $this->order($date);
        }

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertSame(10, $this->client->crmProfile->fresh()->order_cycle_days);
    }

    #[Test]
    #[TestDox('Несколько заказов одним днём — один поход за товаром, а не несколько')]
    public function it_counts_days_not_documents(): void
    {
        $this->order('2026-05-01');
        $this->order('2026-05-01');
        $this->order('2026-05-08');

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertNull($this->client->crmProfile, 'двух дат для ряда мало');
    }

    #[Test]
    #[TestDox('Заполненную менеджером периодичность команда не трогает')]
    public function it_keeps_manager_order_cycle(): void
    {
        CrmClientProfile::create(['user_id' => $this->client->id, 'order_cycle_days' => 30]);

        foreach (['2026-05-01', '2026-05-11', '2026-05-21'] as $date) {
            $this->order($date);
        }

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertSame(30, $this->client->crmProfile->fresh()->order_cycle_days);
    }

    #[Test]
    #[TestDox('Интересы — топ брендов и категорий из отгрузок')]
    public function it_fills_interests_from_shipments(): void
    {
        $category = Category::create(['name' => 'Вибраторы', 'slug' => 'vibrators']);
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->shipmentItem('2026-07-10', 'Satisfyer', 50000, $product);
        $this->shipmentItem('2026-07-20', 'Lola Games', 10000, $product);

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $interests = $this->client->fresh()->tagsWithType(User::INTEREST_TAG_TYPE)
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->all();

        $this->assertSame(['Satisfyer', 'Lola Games', 'Вибраторы'], $interests);
    }

    #[Test]
    #[TestDox('Интересы, заведённые менеджером, не переписываются')]
    public function it_keeps_manager_interests(): void
    {
        $this->client->syncTagsWithType(['Косметика'], User::INTEREST_TAG_TYPE);

        $product = Product::factory()->create();
        $this->shipmentItem('2026-07-10', 'Satisfyer', 50000, $product);

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertSame(
            ['Косметика'],
            $this->client->fresh()->tagsWithType(User::INTEREST_TAG_TYPE)->pluck('name')->map(fn ($n): string => (string) $n)->all(),
        );
    }

    #[Test]
    #[TestDox('--fields ограничивает набор заполняемых полей')]
    public function it_limits_fields(): void
    {
        foreach (['2026-05-01', '2026-05-11', '2026-05-21'] as $date) {
            $this->order($date);
        }

        $this->artisan('crm:enrich-profiles --apply --fields=cycle')->assertSuccessful();

        $this->assertNull($this->client->fresh()->city, 'город в --fields не заказывали');
        $this->assertSame(10, $this->client->crmProfile->fresh()->order_cycle_days);
    }

    #[Test]
    #[TestDox('Неизвестное поле в --fields — ошибка, а не тихий пропуск')]
    public function it_rejects_unknown_field(): void
    {
        $this->artisan('crm:enrich-profiles --apply --fields=colour')->assertFailed();
    }

    #[Test]
    #[TestDox('Пользователи без персонального менеджера в базу партнёров не входят')]
    public function it_skips_users_without_manager(): void
    {
        $outsider = User::factory()->create([
            'erp_name' => 'Петров Пётр ИП, г.Казань',
            'city' => null,
            'personal_manager_id' => null,
        ]);

        $this->artisan('crm:enrich-profiles --apply')->assertSuccessful();

        $this->assertNull($outsider->fresh()->city);
    }

    private function order(string $date): void
    {
        Order::factory()->create([
            'user_id' => $this->client->id,
            'company_id' => null,
            'delivery_address' => null,
            'erp_created_at' => $date.' 10:00:00',
            'created_at' => $date.' 10:00:00',
        ]);
    }

    private function shipmentItem(string $date, string $brand, float $amount, Product $product): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'date' => $date,
            'erp_created_at' => $date.' 10:00:00',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
        ]);

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'brand_name_snapshot' => $brand,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }
}
