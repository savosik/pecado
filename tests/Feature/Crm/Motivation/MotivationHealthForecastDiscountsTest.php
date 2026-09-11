<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\BaseHealthService;
use App\Services\Motivation\DiscountJournalService;
use App\Services\Motivation\FundForecastService;
use App\Services\Motivation\MotivationSchemeInstaller;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Здоровье базы, прогноз фонда, журнал скидок (карточка mot-38; формы B5, B10, B11).
 */
class MotivationHealthForecastDiscountsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $profile;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);

        app(MotivationSchemeInstaller::class)->install($this->month);
    }

    private function ship(User $partner, float $amount, CarbonImmutable $date, float $manualDiscount = 0, float $price = 0): Shipment
    {
        $price = $price > 0 ? $price : $amount;
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $partner->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create(['name' => 'Товар'])->id,
            'quantity' => 1,
            'price' => $price,
            'manual_discount_percent' => $manualDiscount,
            'total' => $amount,
            'subtotal' => $price,
        ]);

        return $shipment;
    }

    #[Test]
    #[TestDox('Здоровье базы: концентрация, активные, не покупавшие, спящие — из тех же строк, что «Моя база»')]
    public function health_metrics(): void
    {
        $big = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Крупный']);
        $small = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Мелкий']);
        $sleeper = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Спящий']);
        User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Никогда']);
        $this->ship($big, 900_000, $this->month->addDays(2));
        $this->ship($small, 100_000, $this->month->addDays(3));
        $this->ship($sleeper, 50_000, $this->month->subMonths(5));
        // Якорь истории, чтобы новизна не спутала карты.
        $this->ship(User::factory()->create(['personal_manager_id' => $this->profile->id]), 1_000, CarbonImmutable::parse('2025-01-15'));

        $data = app(BaseHealthService::class)->build($this->month);
        $row = $data['rows'][0];

        $this->assertSame('Трипуть', $row['manager']['name']);
        $this->assertSame(5, $row['partners_total']);
        $this->assertSame(2, $row['active']);
        $this->assertSame(1, $row['never_bought']);
        $this->assertSame('Крупный', $row['top_partner']['name']);
        $this->assertEqualsWithDelta(0.9, $row['concentration'], 0.0001);
        $this->assertTrue($row['concentration_alert'], 'Выше 40 % — риск концентрации');
        $this->assertSame(2, $row['sleeping'], 'Спящий дольше трёх месяцев — и партнёр-якорь истории');
        $this->assertStringContainsString('filter=never', $row['links']['never']);

        $this->actingAs($this->head)->get('/crm/motivation/health')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Pages/Motivation/Health')->has('rows', 1)->where('department.active', 2));
    }

    #[Test]
    #[TestDox('Прогноз фонда: четыре сценария тем же калькулятором, при плане переменная не ниже текущего темпа')]
    public function fund_forecast_scenarios(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Партнёр']);
        $this->ship($partner, 300_000, $this->month->addDays(1));
        $this->ship(User::factory()->create(['personal_manager_id' => $this->profile->id]), 1_000, CarbonImmutable::parse('2025-01-15'));

        $data = app(FundForecastService::class)->build($this->month);

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertNotNull($row['scenarios']['current']);
        $this->assertNotNull($row['scenarios']['pace']);
        $this->assertGreaterThanOrEqual($row['scenarios']['current']['total'], $row['scenarios']['pace']['total']);
        $this->assertTrue($data['department']['current']['available']);
        $this->assertSame(['current', 'pace', 'plan', 'over'], array_keys($data['scenarios']));

        $this->actingAs($this->head)->get('/crm/motivation/forecast')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Pages/Motivation/Forecast')->has('rows', 1)->has('notes', 2));
    }

    #[Test]
    #[TestDox('Журнал скидок: только ручные скидки, группировка по документу, сумма скидки от базовой цены')]
    public function discount_journal(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Партнёр']);
        $discounted = $this->ship($partner, 80_000, $this->month->addDays(1), manualDiscount: 20, price: 100_000);
        $this->ship($partner, 50_000, $this->month->addDays(2));

        $data = app(DiscountJournalService::class)->build($this->month, $this->month->endOfMonth(), null, 0, 1);

        $this->assertSame(1, $data['summary']['documents']);
        $this->assertSame(1, $data['summary']['partners']);
        $this->assertSame(20.0, $data['summary']['max_percent']);
        $this->assertSame(20_000.0, $data['summary']['discount']);
        $this->assertSame($discounted->id, $data['rows']['data'][0]['shipment_id']);
        $this->assertSame('Трипуть', $data['rows']['data'][0]['manager_name']);
        $this->assertSame(20_000.0, $data['rows']['data'][0]['items'][0]['discount']);

        $this->assertSame(0, app(DiscountJournalService::class)->build($this->month, $this->month->endOfMonth(), null, 30, 1)['summary']['documents'], 'Фильтр «скидка от 30 %»');

        $this->actingAs($this->head)->get('/crm/motivation/discounts?from='.$this->month->toDateString().'&to='.$this->month->endOfMonth()->toDateString())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Pages/Motivation/Discounts')->where('summary.documents', 1));

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/discounts')->assertForbidden();
        $this->actingAs($manager)->get('/crm/motivation/health')->assertForbidden();
        $this->actingAs($manager)->get('/crm/motivation/forecast')->assertForbidden();
    }
}
