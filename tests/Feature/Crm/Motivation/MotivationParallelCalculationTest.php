<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationShadowCalculation;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\ParallelCalculationService;
use App\Services\Motivation\TeamSummaryService;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Параллельный расчёт переходного периода (карточка mot-39; п. 12.2 Положения).
 */
class MotivationParallelCalculationTest extends TestCase
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
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);

        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Партнёр']);
        $this->ship($partner, 500_000, $this->month->addDays(2));
        $this->ship(User::factory()->create(['personal_manager_id' => $this->profile->id]), 1_000, CarbonImmutable::parse('2025-01-15'));
    }

    private function ship(User $partner, float $amount, CarbonImmutable $date): void
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
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }

    #[Test]
    #[TestDox('До введения: выплата по прежней схеме, справочно — по Положению 2.2 с разложением и объяснением')]
    public function before_introduction_shadow_is_v2(): void
    {
        app(MotivationSchemeInstaller::class)->install($this->month->addMonth());   // 2.2 — со следующего месяца

        $paying = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $comparison = app(ParallelCalculationService::class)->compare($paying);

        $this->assertNotNull($comparison);
        $this->assertSame('before', $comparison['phase']);
        $this->assertFalse($comparison['paying']['is_v2']);
        $this->assertTrue($comparison['shadow']['is_v2']);
        $this->assertSame('Отдел продаж — Положение 2.2', $comparison['shadow']['scheme_label']);
        $this->assertNotEmpty($comparison['shadow']['lines']);
        $this->assertContains('base_sales', array_column($comparison['shadow']['lines'], 'key'), 'Строки П1–К1 презентера');
        $this->assertNotEmpty($comparison['categories']);
        $this->assertEqualsWithDelta($comparison['shadow']['total'] - $comparison['paying']['total'], $comparison['difference'], 0.01);
        $this->assertNotSame('', $comparison['explanation']);

        $this->assertSame(1, MotivationShadowCalculation::query()->count(), 'Справочный снимок сохранён отдельно');
        $this->assertSame(1, \App\Models\PayrollCalculation::query()->count(), 'Оплачиваемый снимок один');

        // Страница работника: блок сравнения вместо «показатели не рассчитываются».
        $this->actingAs($this->head)->get('/crm/motivation')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Index')
                ->where('calculation.on_scheme_v2', false)
                ->where('calculation.parallel.phase', 'before')
                ->has('calculation.parallel.categories'));

        // Сводка руководителя: колонка и стоимость перехода.
        $team = app(TeamSummaryService::class)->build($this->month);
        $this->assertNotNull($team['parallel']);
        $this->assertSame('before', $team['parallel']['phase']);
        $this->assertEqualsWithDelta($comparison['difference'], $team['parallel']['difference'], 0.01);
        $this->assertEqualsWithDelta($comparison['shadow']['total'], $team['rows'][0]['parallel']['total'], 0.01);
    }

    #[Test]
    #[TestDox('После введения: выплата по 2.2, справочно — прежняя схема; за пределами окна сравнения нет')]
    public function after_introduction_shadow_is_previous_scheme(): void
    {
        app(MotivationSchemeInstaller::class)->install($this->month->subMonth());   // 2.2 действует второй месяц

        $paying = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $comparison = app(ParallelCalculationService::class)->compare($paying);

        $this->assertNotNull($comparison);
        $this->assertSame('after', $comparison['phase']);
        $this->assertTrue($comparison['paying']['is_v2']);
        $this->assertFalse($comparison['shadow']['is_v2']);
        $this->assertContains('salary', array_column($comparison['shadow']['lines'], 'key'));

        // Через три месяца после введения окно закрыто.
        $late = $this->month->subMonth()->addMonths(3);
        $this->assertFalse(app(ParallelCalculationService::class)->inWindow($late));
        $this->assertTrue(app(ParallelCalculationService::class)->inWindow($this->month->subMonths(3)), 'Два периода до введения — в окне');
        $this->assertFalse(app(ParallelCalculationService::class)->inWindow($this->month->subMonths(4)));
    }

    #[Test]
    #[TestDox('Без схемы 2.2 параллельного расчёта нет; у замороженного месяца справочный снимок не пересчитывается')]
    public function no_window_without_v2_and_frozen_shadow_is_kept(): void
    {
        $calculations = app(PayrollCalculationService::class);
        $paying = $calculations->ensureDraft($this->profile->id, $this->month);
        $this->assertNull(app(ParallelCalculationService::class)->compare($paying));

        app(MotivationSchemeInstaller::class)->install($this->month->addMonth());
        $paying = $calculations->ensureDraft($this->profile->id, $this->month);
        $first = app(ParallelCalculationService::class)->compare($paying);
        $calculations->approve($paying->fresh(), $this->head);

        $this->ship(User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Новый']), 300_000, $this->month->addDays(5));
        MotivationShadowCalculation::query()->update(['computed_at' => now()->subHours(2)]);

        $again = app(ParallelCalculationService::class)->compare($paying->fresh());
        $this->assertSame($first['shadow']['total'], $again['shadow']['total'], 'Утверждённый месяц: справочный снимок заморожен вместе с ним');
    }
}
