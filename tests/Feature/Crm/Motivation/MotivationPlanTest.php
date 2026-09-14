<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\Crm\PlanTarget;
use App\Enums\UserKind;
use App\Models\CrmSalesPlan;
use App\Models\ManagerAbsence;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\PlanCalculator;
use App\Services\Motivation\PlanOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Личный план на квартал (карточка mot-25).
 *
 * Проверяется формула пункта 5.2 и три запрета: вето на повышение по результату,
 * предел снижения и его неприменимость при смене методики.
 */
class MotivationPlanTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $partner;

    private User $head;

    private CarbonImmutable $quarter;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->quarter = CarbonImmutable::parse('2026-10-01');
        // Окно выборки обрезается сегодняшним днём: без фиксации времени
        // результат зависел бы от даты прогона.
        $this->travelTo($this->quarter->setTime(9, 0));
        $this->manager = PersonalManager::factory()->create([
            'user_id' => User::factory()->create(['user_kind' => UserKind::STAFF->value])->id,
        ]);
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->partner = User::factory()->create(['personal_manager_id' => $this->manager->id]);
    }

    /**
     * Ровные отгрузки: заданная сумма в каждый рабочий день окна выборки.
     */
    private function evenShipments(float $perDay, string $from = '2026-04-01', string $to = '2026-09-30'): void
    {
        $calendar = app(\App\Services\Payroll\Support\WorkingCalendar::class);
        $day = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        for (; $day->lte($end); $day = $day->addDay()) {
            if (! $calendar->isWorkingDay($day)) {
                continue;
            }

            $shipment = Shipment::create([
                'uuid' => (string) Str::uuid(),
                'erp_number' => '29УТ-'.random_int(100000, 999999),
                'user_id' => $this->partner->id,
                'date' => $day->toDateString(),
                'erp_created_at' => $day,
                'status' => 'completed',
                'currency_code' => 'RUB',
                'total_amount' => $perDay,
            ]);

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'product_id' => Product::factory()->create()->id,
                'quantity' => 1,
                'price' => $perDay,
                'total' => $perDay,
                'subtotal' => $perDay,
            ]);
        }
    }

    private function plan(string $month, float $amount): void
    {
        CrmSalesPlan::query()->create([
            'period_month' => $month,
            'target_type' => PlanTarget::MANAGER->value,
            'target_id' => $this->manager->id,
            'amount' => $amount,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(bool $waive = false): array
    {
        return app(PlanCalculator::class)->calculate($this->manager->id, $this->quarter, [], $waive);
    }

    #[Test]
    #[TestDox('План месяца — медиана за рабочий день × рабочие дни × сезон × прирост')]
    public function plan_follows_the_formula(): void
    {
        $this->evenShipments(100_000);

        $result = $this->calculate();

        $this->assertSame(100_000.0, $result['median_per_day']);
        $this->assertSame(22, $result['working_days']['2026-10-01'], 'В октябре 2026 — 22 рабочих дня');
        $this->assertSame(2_200_000.0, $result['values']['2026-10-01']);
        $this->assertSame(2_000_000.0, $result['values']['2026-11-01'], 'В ноябре 20 рабочих дней');
    }

    #[Test]
    #[TestDox('Сезонный коэффициент и целевой прирост умножают базу')]
    public function seasonal_and_growth_multiply_the_base(): void
    {
        $this->evenShipments(100_000);

        $result = app(PlanCalculator::class)->calculate($this->manager->id, $this->quarter, [
            'growth_rate' => 0.1,
            'seasonal' => [10 => 1.2, 11 => 1.0, 12 => 1.5],
        ]);

        $this->assertSame(2_904_000.0, $result['values']['2026-10-01'], '100 000 × 22 × 1,2 × 1,1');
        $this->assertSame(3_630_000.0, $result['values']['2026-12-01'], '100 000 × 22 × 1,5 × 1,1');
    }

    #[Test]
    #[TestDox('Дни отсутствия из выборки исключаются, а не считаются нулями')]
    public function absence_days_are_excluded_from_the_sample(): void
    {
        $this->evenShipments(100_000, '2026-04-01', '2026-08-31');
        // Сентябрь работник в отпуске и не отгружал: без исключения по табелю
        // эти дни вошли бы в выборку нулями и обвалили медиану.
        ManagerAbsence::query()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => null,
            'type' => 'vacation',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);

        $result = $this->calculate();

        $this->assertSame(100_000.0, $result['median_per_day']);
        $this->assertSame(22, $result['sample']['excluded_days']);
        $this->assertSame(0, $result['sample']['zero_days']);
    }

    #[Test]
    #[TestDox('Меньшинство дней без отгрузок медиану не сдвигает, но видно в выборке')]
    public function zero_days_are_reported_and_do_not_shift_the_median(): void
    {
        $this->evenShipments(100_000, '2026-04-01', '2026-08-31');

        $result = $this->calculate();

        $this->assertSame(22, $result['sample']['zero_days'], 'Сентябрь без отгрузок и без табеля — двадцать два нуля');
        $this->assertSame(
            100_000.0,
            $result['median_per_day'],
            'Медиана устойчива к меньшинству нулей — ровно поэтому норма требует её, а не среднее',
        );
    }

    #[Test]
    #[TestDox('Дни после сегодняшнего в выборку не входят: неотработанное — не ноль')]
    public function future_days_are_not_counted_as_zeros(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00'));
        $this->evenShipments(100_000, '2026-04-01', '2026-09-14');

        $result = $this->calculate();

        $this->assertSame('2026-09-14', $result['sample']['to']);
        $this->assertSame(0, $result['sample']['zero_days'], 'С 15 по 30 сентября ещё не работали');
        $this->assertSame(100_000.0, $result['median_per_day']);
    }

    #[Test]
    #[TestDox('Медиана — по месячным значениям: регулярные крупные дни не выбрасываются')]
    public function median_is_taken_over_monthly_values(): void
    {
        $this->evenShipments(50_000);

        // В первый рабочий день каждого месяца — крупная отгрузка. Медиана дней
        // её отбросила бы (50 000 ₽), хотя это обычная работа каждый месяц.
        $calendar = app(\App\Services\Payroll\Support\WorkingCalendar::class);
        for ($month = CarbonImmutable::parse('2026-04-01'); $month->lt($this->quarter); $month = $month->addMonth()) {
            $day = $month;
            while (! $calendar->isWorkingDay($day)) {
                $day = $day->addDay();
            }
            $this->evenShipments(1_000_000, $day->toDateString(), $day->toDateString());
        }

        $result = $this->calculate();

        // Апрель–сентябрь 2026: 22, 19, 21, 23, 21, 22 рабочих дня; 50 000 + 1 000 000 ÷ дни.
        // Средние по порядку: 93 478,26 · 95 454,55 · 95 454,55 · 97 619,05 · 97 619,05 · 102 631,58.
        $this->assertEqualsWithDelta(96_536.80, $result['median_per_day'], 0.01);
        $this->assertCount(6, $result['sample']['by_month']);
        $this->assertEqualsWithDelta(95_454.55, $result['sample']['by_month'][0]['per_day'], 0.01);
    }

    #[Test]
    #[TestDox('Сезонность и прирост берутся из действующего приказа параметров')]
    public function seasonal_and_growth_come_from_the_parameter_order(): void
    {
        $this->evenShipments(100_000);

        $seasonal = array_fill(1, 12, 1.0);
        $seasonal[10] = 1.2;

        \App\Models\Motivation\MotivationParameterOrder::query()->create([
            'effective_from' => '2026-09-01',
            'values' => app(\App\Services\Motivation\ParameterCatalog::class)->complete(['seasonal' => $seasonal, 'growth_rate' => 0.1]),
            'author_id' => $this->head->id,
        ]);

        $result = $this->calculate();

        $this->assertSame(0.1, $result['growth_rate']);
        $this->assertSame(2_904_000.0, $result['values']['2026-10-01'], '100 000 × 22 × 1,2 × 1,1');
        $this->assertSame(2_200_000.0, $result['values']['2026-11-01'], '100 000 × 20 × 1,0 × 1,1');
    }

    #[Test]
    #[TestDox('Месяцы выборки приводятся к сезону 1,0 до медианы')]
    public function sample_months_are_deseasonalized_before_the_median(): void
    {
        $this->evenShipments(100_000);

        // Всё окно выборки — слабый сезон 0,8; октябрь — 1,0.
        $seasonal = array_fill(1, 12, 0.8);
        $seasonal[10] = 1.0;

        \App\Models\Motivation\MotivationParameterOrder::query()->create([
            'effective_from' => '2026-09-01',
            'values' => app(\App\Services\Motivation\ParameterCatalog::class)->complete(['seasonal' => $seasonal]),
            'author_id' => $this->head->id,
        ]);

        $result = $this->calculate();

        $this->assertSame(125_000.0, $result['median_per_day'], '100 000 ÷ 0,8: слабый сезон не занижает базу');
        $this->assertSame(100_000.0, $result['sample']['by_month'][0]['per_day']);
        $this->assertSame(125_000.0, $result['sample']['by_month'][0]['per_day_adjusted']);
        $this->assertSame(2_750_000.0, $result['values']['2026-10-01'], '125 000 × 22 × 1,0');
        $this->assertSame(2_000_000.0, $result['values']['2026-11-01'], '125 000 × 20 × 0,8 — сезон возвращается множителем');
    }

    #[Test]
    #[TestDox('Простой большую часть периода медиану обрушивает')]
    public function majority_of_idle_days_collapses_the_median(): void
    {
        // Отгрузки только в апреле и мае, остальные четыре месяца работник простаивал.
        $this->evenShipments(100_000, '2026-04-01', '2026-05-31');

        $result = $this->calculate();

        $this->assertSame(0.0, $result['median_per_day'], 'Больше половины дней без отгрузок — медиана ноль');
        $this->assertGreaterThan($result['sample']['days'] / 2, $result['sample']['zero_days']);
    }

    #[Test]
    #[TestDox('Отгрузки партнёра в периоде новизны план не увеличивают')]
    public function new_partner_revenue_does_not_raise_the_plan(): void
    {
        $this->evenShipments(100_000);

        $newcomer = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        MotivationPartnerNovelty::factory()->withinNovelty('2026-04-01', '2026-09-30')
            ->create(['user_id' => $newcomer->id]);

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $newcomer->id,
            'date' => '2026-06-10',
            'erp_created_at' => CarbonImmutable::parse('2026-06-10'),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 5_000_000,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => 5_000_000,
            'total' => 5_000_000,
            'subtotal' => 5_000_000,
        ]);

        $this->assertSame(100_000.0, $this->calculate()['median_per_day'], 'Привлечение партнёра само себе планку не поднимает (п. 6.3.3)');
    }

    #[Test]
    #[TestDox('Учитывается половина перевыполнения предыдущего квартала')]
    public function half_of_overperformance_is_carried(): void
    {
        $this->evenShipments(100_000);

        // Планы третьего квартала заведомо ниже факта.
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $this->plan($month, 1_000_000);
        }

        $result = $this->calculate();

        $this->assertGreaterThan(0, $result['overperformance_carry']);
        $this->assertSame(
            round($result['median_per_day'] * 22 + $result['overperformance_carry'] / 3, 2),
            $result['values']['2026-10-01'],
        );
    }

    #[Test]
    #[TestDox('Предел снижения не применяется, пока прошлый квартал поставлен по другой методике')]
    public function decline_limit_waits_for_a_comparable_quarter(): void
    {
        $this->evenShipments(100_000);

        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $this->plan($month, 20_000_000);   // прежняя методика, сверху вниз
        }

        $result = $this->calculate();

        $this->assertFalse($result['previous_quarter_comparable']);
        $this->assertFalse($result['decline_limited'], 'Иначе смена методики отменяется сама собой');
        $this->assertSame(2_200_000.0, $result['values']['2026-10-01']);
    }

    #[Test]
    #[TestDox('Предел снижения работает, когда прошлый квартал утверждён приказом системы')]
    public function decline_limit_applies_to_a_comparable_quarter(): void
    {
        $this->evenShipments(100_000);

        MotivationPlanOrder::factory()->create([
            'quarter_start' => '2026-07-01',
            'personal_manager_id' => $this->manager->id,
            'status' => MotivationPlanOrder::STATUS_APPROVED,
        ]);
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $this->plan($month, 5_000_000);   // 15 млн за квартал
        }

        $result = $this->calculate();

        $this->assertTrue($result['previous_quarter_comparable']);
        $this->assertTrue($result['decline_limited']);
        $this->assertEqualsWithDelta(12_000_000.0, array_sum($result['values']), 1.0, 'Не ниже 80 % от 15 млн');
    }

    #[Test]
    #[TestDox('Предел снимается основанием — передачей партнёров')]
    public function decline_limit_can_be_waived_with_a_reason(): void
    {
        $this->evenShipments(100_000);

        MotivationPlanOrder::factory()->create([
            'quarter_start' => '2026-07-01',
            'personal_manager_id' => $this->manager->id,
            'status' => MotivationPlanOrder::STATUS_APPROVED,
        ]);
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $this->plan($month, 5_000_000);
        }

        $result = $this->calculate(waive: true);

        $this->assertFalse($result['decline_limited']);
        $this->assertSame(2_200_000.0, $result['values']['2026-10-01']);
    }

    #[Test]
    #[TestDox('Повышение плана со ссылкой на перевыполнение блокируется')]
    public function raising_the_plan_for_overperformance_is_blocked(): void
    {
        $this->evenShipments(100_000);

        $service = app(PlanOrderService::class);
        $order = $service->draft($this->manager->id, $this->quarter, [], $this->head);

        try {
            $service->override($order, ['2026-10-01' => 9_000_000], 'Перевыполнил прошлый месяц вдвое');
            $this->fail('Пункт 5.4 запрещает повышение по этому основанию');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('5.4', $e->getMessage());
        }

        // Снижение с тем же обоснованием проходит: запрет касается повышения.
        $lowered = $service->override($order, ['2026-10-01' => 1_000_000], 'Перевыполнение прошлого квартала, партнёры переданы');
        $this->assertSame(1_000_000.0, (float) $lowered->values['2026-10-01']);
    }

    #[Test]
    #[TestDox('Отклонение от расчётного требует обоснования')]
    public function override_requires_a_comment(): void
    {
        $this->evenShipments(100_000);

        $service = app(PlanOrderService::class);
        $order = $service->draft($this->manager->id, $this->quarter, [], $this->head);

        $this->expectException(\InvalidArgumentException::class);
        $service->override($order, ['2026-10-01' => 1_000_000], '   ');
    }

    #[Test]
    #[TestDox('Утверждение приказа записывает значения в планы продаж')]
    public function approval_writes_values_into_sales_plans(): void
    {
        $this->evenShipments(100_000);

        $service = app(PlanOrderService::class);
        $order = $service->approve($service->draft($this->manager->id, $this->quarter, [], $this->head), $this->head);

        $this->assertSame(MotivationPlanOrder::STATUS_APPROVED, $order->status);

        foreach (['2026-10-01' => 2_200_000.0, '2026-11-01' => 2_000_000.0, '2026-12-01' => 2_200_000.0] as $month => $expected) {
            $plan = CrmSalesPlan::query()
                ->forPeriod(CarbonImmutable::parse($month))
                ->forManager($this->manager->id)
                ->sole();

            $this->assertSame($expected, (float) $plan->amount, 'План читается всеми экранами CRM из одного места');
        }
    }

    #[Test]
    #[TestDox('Утверждённый приказ не редактируется')]
    public function approved_order_is_immutable(): void
    {
        $this->evenShipments(100_000);

        $service = app(PlanOrderService::class);
        $order = $service->approve($service->draft($this->manager->id, $this->quarter, [], $this->head), $this->head);

        $this->expectException(\InvalidArgumentException::class);
        $service->override($order, ['2026-10-01' => 1_000_000], 'Пересмотр');
    }

    #[Test]
    #[TestDox('Черновик пересчитывается, а не размножается')]
    public function draft_is_recalculated_in_place(): void
    {
        $this->evenShipments(100_000);

        $service = app(PlanOrderService::class);
        $service->draft($this->manager->id, $this->quarter, [], $this->head);
        $service->draft($this->manager->id, $this->quarter, [], $this->head);

        $this->assertSame(1, MotivationPlanOrder::query()->forQuarter($this->quarter)->count());
    }
}
