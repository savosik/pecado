<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationFocusSnapshotItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductAvailabilityEvent;
use App\Models\User;
use App\Services\Motivation\FocusRangeResolver;
use App\Services\Motivation\FocusRuleService;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Фокус-перечень руководителя (карточка mot-36): правила, даты по п. 6.4.3, снимок состава.
 */
class MotivationFocusAdminTest extends TestCase
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

    #[Test]
    #[TestDox('Включение не раньше первой партии; исключение не раньше первого числа следующего периода')]
    public function dates_follow_clause_6_4_3(): void
    {
        $brand = Brand::factory()->create(['name' => 'Pecado']);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $arrival = CarbonImmutable::today()->subDays(10);
        ProductAvailabilityEvent::query()->create(['product_id' => $product->id, 'event' => ProductAvailabilityEvent::IN_STOCK, 'quantity' => 5, 'happened_at' => $arrival]);

        $service = app(FocusRuleService::class);

        try {
            $service->create(['scope' => 'brand', 'target_id' => $brand->id, 'starts_on' => $arrival->subDay()->toDateString()], $this->head);
            $this->fail('Раньше первой партии');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('не раньше поступления первой партии — '.$arrival->format('d.m.Y'), $e->getMessage());
        }

        $rule = $service->create(['scope' => 'brand', 'target_id' => $brand->id, 'starts_on' => $arrival->toDateString(), 'rate' => 0.03], $this->head);
        $this->assertSame([$product->id => 0.03], app(FocusRangeResolver::class)->itemsFor($this->month));

        try {
            $service->close($rule, CarbonImmutable::today());
            $this->fail('Исключение внутри текущего периода');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('не ранее '.CarbonImmutable::today()->endOfMonth()->format('d.m.Y'), $e->getMessage());
        }

        $service->close($rule, CarbonImmutable::today()->endOfMonth());
        $this->assertSame(CarbonImmutable::today()->endOfMonth()->toDateString(), $rule->fresh()->ends_on->toDateString());
    }

    #[Test]
    #[TestDox('Утверждение расчёта замораживает состав: правка правил не меняет утверждённый период')]
    public function approval_freezes_composition(): void
    {
        $category = Category::factory()->create(['name' => 'Смазки']);
        $product = Product::factory()->create(['category_id' => $category->id, 'created_at' => $this->month->subMonths(2)]);
        $other = Product::factory()->create(['category_id' => $category->id, 'created_at' => $this->month->subMonths(2)]);

        MotivationFocusRule::factory()->create(['scope' => 'category', 'target_id' => $category->id, 'starts_on' => $this->month->subMonth()->toDateString(), 'rate' => null]);

        $calculations = app(PayrollCalculationService::class);
        $calculations->approve($calculations->ensureDraft($this->profile->id, $this->month), $this->head);

        $snapshot = MotivationFocusSnapshotItem::query()->forPeriod($this->month)->get();
        $this->assertCount(2, $snapshot);
        $this->assertEqualsWithDelta(0.01, (float) $snapshot->first()->rate, 0.000001, 'В снимке общая ставка П3 из приказа');

        // Правило закрыли и добавили новое — утверждённый месяц читает снимок.
        MotivationFocusRule::query()->update(['ends_on' => $this->month->subMonth()->endOfMonth()->toDateString()]);
        $third = Product::factory()->create(['category_id' => $category->id, 'created_at' => $this->month->subMonth()]);
        MotivationFocusRule::factory()->create(['scope' => 'product', 'target_id' => $third->id, 'starts_on' => $this->month->toDateString()]);

        $frozen = app(FocusRangeResolver::class)->itemsFor($this->month);
        $this->assertSame([$product->id, $other->id], array_keys($frozen));

        // Включение датой внутри утверждённого периода отклоняется.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/заморожен/');
        app(FocusRuleService::class)->create(['scope' => 'product', 'target_id' => $third->id, 'starts_on' => $this->month->addDays(3)->toDateString()], $this->head);
    }

    #[Test]
    #[TestDox('Страница руководителя: правила, состав, отдача, поиск и добавление через API; менеджеру закрыта')]
    public function page_and_endpoints(): void
    {
        $brand = Brand::factory()->create(['name' => 'Pecado']);
        Product::factory()->count(2)->create(['brand_id' => $brand->id, 'created_at' => $this->month->subMonth()]);

        $this->actingAs($this->head)
            ->getJson('/crm/motivation/focus-list/search?scope=brand&q=Pec')
            ->assertOk()
            ->assertJsonPath('options.0.name', 'Pecado')
            ->assertJsonPath('options.0.hint', '2 поз.');

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/focus-list/rules', [
                'scope' => 'brand', 'target_id' => $brand->id, 'starts_on' => $this->month->toDateString(),
                'order_number' => '12-к', 'order_date' => $this->month->toDateString(), 'comment' => 'Поддержка марки',
            ])
            ->assertOk()
            ->assertJsonPath('rules.0.target_name', 'Pecado')
            ->assertJsonPath('rules.0.status', 'active')
            ->assertJsonPath('composition.frozen', false)
            ->assertJsonCount(2, 'composition.items');

        $this->actingAs($this->head)
            ->get('/crm/motivation/focus-list')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/FocusAdmin')
                ->has('rules', 1)
                ->has('returns', 6)
                ->where('can_edit', true));

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/focus-list/freeze?month='.$this->month->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('composition.frozen', true);

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/focus-list')->assertForbidden();
    }
}
