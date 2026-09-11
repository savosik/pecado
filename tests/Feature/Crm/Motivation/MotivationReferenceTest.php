<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Brand;
use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\FocusListService;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\NoveltyCalculator;
use App\Services\Motivation\PlanReferenceService;
use App\Services\Motivation\QuarterReferenceService;
use App\Services\Payroll\PayrollCalculationService;
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
 * Справочные экраны: «Фокус-товары», «Откуда мой план», «Премия отдела» (карточка mot-30).
 */
class MotivationReferenceTest extends TestCase
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

    private function ship(User $partner, float $amount, CarbonImmutable $date, ?Product $product = null): void
    {
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
            'product_id' => ($product ?? Product::factory()->create())->id,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }

    #[Test]
    #[TestDox('Фокус-товары: состав из правил, «кому предложить» по категориям перечня, заработано из снимка')]
    public function focus_lists_items_partners_and_earnings(): void
    {
        $brand = Brand::factory()->create();
        $category = \App\Models\Category::factory()->create(['name' => 'Смазки']);
        $focusProduct = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Фокусная позиция']);
        $similar = Product::factory()->create(['category_id' => $category->id, 'name' => 'Похожая позиция']);

        MotivationFocusRule::factory()->create([
            'scope' => MotivationFocusRule::SCOPE_BRAND,
            'target_id' => $brand->id,
            'starts_on' => $this->month->subMonth()->toDateString(),
            'rate' => null,
        ]);

        $buyer = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Берёт похожее']);
        $other = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Не из этих категорий']);
        $this->ship($buyer, 120_000, $this->month->subMonths(2)->addDays(3), $similar);
        $this->ship($buyer, 30_000, $this->month->addDays(2), $focusProduct);
        $this->ship($other, 500_000, $this->month->addDays(2));

        $calculation = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $data = app(FocusListService::class)->build($this->profile->id, $this->month, $calculation);

        $this->assertCount(1, $data['items']);
        $this->assertSame('Фокусная позиция', $data['items'][0]['name']);
        $this->assertFalse($data['items'][0]['own_rate']);

        $this->assertCount(1, $data['partners'], 'Только тот, кто покупает категории перечня');
        $this->assertSame('Берёт похожее', $data['partners'][0]['name']);
        $this->assertSame(30_000.0, $data['partners'][0]['focus_this_month']);
        $this->assertContains('Смазки', $data['partners'][0]['categories']);
        $this->assertGreaterThan(0, $data['partners'][0]['your_gain']);

        $this->assertSame(30_000.0, $data['earned']['revenue']);
        $this->assertEqualsWithDelta(300.0, $data['earned']['amount'], 0.01, 'П3 = 1 % от отгрузок перечня');
    }

    #[Test]
    #[TestDox('Откуда мой план: без приказа — предварительный расчёт, с приказом — его значения')]
    public function plan_reference_prefers_the_approved_order(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id]);
        $this->ship($partner, 100_000, $this->month->subMonths(2)->addDays(3));

        $preliminary = app(PlanReferenceService::class)->build($this->profile->id, $this->month);

        $this->assertFalse($preliminary['approved']);
        $this->assertCount(3, $preliminary['months']);
        $this->assertCount(6, $preliminary['steps']);
        $this->assertNotEmpty($preliminary['sample']['by_month']);
        $this->assertNotEmpty($preliminary['rules']);

        MotivationPlanOrder::factory()->create([
            'quarter_start' => $this->month->startOfQuarter()->toDateString(),
            'personal_manager_id' => $this->profile->id,
            'status' => MotivationPlanOrder::STATUS_APPROVED,
            'median_per_day' => 123_456,
            'values' => [
                $this->month->startOfQuarter()->toDateString() => 2_000_000,
                $this->month->startOfQuarter()->addMonth()->toDateString() => 2_100_000,
                $this->month->startOfQuarter()->addMonths(2)->toDateString() => 2_200_000,
            ],
        ]);

        $approved = app(PlanReferenceService::class)->build($this->profile->id, $this->month);

        $this->assertTrue($approved['approved']);
        $this->assertSame(123_456.0, $approved['steps'][0]['value'], 'Медиана — из приказа, а не из пересчёта');
        $this->assertSame(2_000_000.0, $approved['months'][0]['plan']);
    }

    #[Test]
    #[TestDox('Премия отдела: ступени с формулировкой, кандидаты всего отдела, средней покупки нет')]
    public function quarter_reference_shows_steps_and_candidates_without_averages(): void
    {
        $colleague = PersonalManager::factory()->create(['name' => 'Коллега']);
        $anchor = User::factory()->create(['personal_manager_id' => $this->profile->id]);
        $this->ship($anchor, 10_000, CarbonImmutable::parse('2025-01-15'));

        $mine = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Мой новый']);
        $theirs = User::factory()->create(['personal_manager_id' => $colleague->id, 'name' => 'Чужой новый']);
        $this->ship($mine, 150_000, $this->month->startOfQuarter()->addDays(5));
        $this->ship($theirs, 40_000, $this->month->startOfQuarter()->addDays(6));

        app(NoveltyCalculator::class)->rebuild();

        $data = app(QuarterReferenceService::class)->build($this->month);

        $this->assertSame(2, $data['candidates_count'], 'Партнёры всего отдела, а не только свои');
        $this->assertSame(1, $data['qualified_count']);
        $this->assertSame(0, $data['step_reached']);
        $this->assertSame(7, $data['next_step']['partners_needed']);
        $this->assertSame('Мой новый', $data['candidates'][0]['name'], 'Засчитанные первыми');
        $this->assertSame('Коллега', $data['candidates'][1]['manager']);
        $this->assertSame(60_000.0, $data['candidates'][1]['shortfall']);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('average', (string) $json);
        $this->assertStringNotContainsString('средн', mb_strtolower((string) $json));
    }

    #[Test]
    #[TestDox('Страницы открываются руководителю; премия отдела видна и без карточки; менеджеру без права — 403')]
    public function pages_respect_permissions(): void
    {
        $this->actingAs($this->head)->get('/crm/motivation/focus')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Pages/Motivation/Focus')->has('data.items'));
        $this->actingAs($this->head)->get('/crm/motivation/plan')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Pages/Motivation/Plan')->where('data.approved', false));
        $this->actingAs($this->head)->get('/crm/motivation/quarter')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Pages/Motivation/Quarter')->has('data.steps', 3));

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/quarter')->assertForbidden();
    }
}
