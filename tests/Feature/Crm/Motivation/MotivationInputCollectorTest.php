<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\ReturnStatus;
use App\Models\Brand;
use App\Models\ManagerAbsence;
use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationFocusSnapshotItem;
use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\MotivationInputCollector;
use App\Services\Motivation\OverdueDebtIntegrator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Motivation\Concerns\RegistersInvoicesInLedger;
use Tests\TestCase;

/**
 * Сбор входов переменной части (карточка mot-22).
 *
 * Проверяется то, что нельзя увидеть в компонентах: правильно ли разложены
 * отгрузки по трём группам, как считается интеграл долга по дням и что делает
 * табель с планом.
 */
class MotivationInputCollectorTest extends TestCase
{
    use RefreshDatabase;
    use RegistersInvoicesInLedger;

    private PersonalManager $manager;

    private User $base;

    private User $newcomer;

    private User $foreign;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->month = Carbon::parse('2026-06-01');
        $this->manager = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->base = User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => 'Старый партнёр']);
        $this->newcomer = User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => 'Новый партнёр']);
        $this->foreign = User::factory()->create(['personal_manager_id' => PersonalManager::factory()->create()->id]);
    }

    private function shipment(User $client, float $total, ?Carbon $date = null, ?Product $product = null): Shipment
    {
        $date ??= $this->month->copy()->addDays(2);

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $client->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => ($product ?? Product::factory()->create())->id,
            'quantity' => 1,
            'price' => $total,
            'total' => $total,
            'subtotal' => $total,
        ]);

        return $shipment;
    }

    private function productReturn(User $client, float $total, ReturnStatus $status): ProductReturn
    {
        $return = ProductReturn::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'status' => $status->value,
            'total_amount' => $total,
        ]);

        // created_at — не fillable, а датой возврата служит именно она:
        // документа 1С у возвратов нет, есть только дата записи.
        $return->forceFill(['created_at' => Carbon::parse('2026-06-10')])->saveQuietly();

        return $return;
    }

    private function collect(): \App\Services\Motivation\Dto\MotivationInputs
    {
        return app(MotivationInputCollector::class)->collect($this->manager->id, $this->month);
    }

    #[Test]
    #[TestDox('Отгрузки нового партнёра идут в П2 и не попадают в базу П1')]
    public function novelty_splits_revenue_into_two_exclusive_groups(): void
    {
        MotivationPartnerNovelty::factory()->withinNovelty('2026-05-01', '2026-10-31')
            ->create(['user_id' => $this->newcomer->id]);

        $this->shipment($this->base, 400_000);
        $this->shipment($this->newcomer, 150_000);
        $this->shipment($this->foreign, 900_000);

        $inputs = $this->collect();

        $this->assertSame(400_000.0, $inputs->baseRevenue);
        $this->assertSame(150_000.0, $inputs->newPartnersRevenue);
        $this->assertSame(1, $inputs->newPartnersCount);
    }

    #[Test]
    #[TestDox('Партнёр вне периода новизны считается закреплённой базой')]
    public function partner_outside_novelty_window_counts_as_base(): void
    {
        MotivationPartnerNovelty::factory()->withinNovelty('2026-01-01', '2026-05-31')
            ->create(['user_id' => $this->newcomer->id]);

        $this->shipment($this->base, 400_000);
        $this->shipment($this->newcomer, 150_000);

        $inputs = $this->collect();

        $this->assertSame(550_000.0, $inputs->baseRevenue, 'Период новизны истёк в мае — июньские отгрузки идут в П1');
        $this->assertSame(0.0, $inputs->newPartnersRevenue);
    }

    #[Test]
    #[TestDox('Фокус-товары считаются по всем партнёрам и не исключаются из П1')]
    public function focus_revenue_overlaps_with_the_other_groups(): void
    {
        $brand = Brand::factory()->create();
        $focusProduct = Product::factory()->create(['brand_id' => $brand->id]);
        MotivationFocusRule::factory()->create([
            'scope' => MotivationFocusRule::SCOPE_BRAND,
            'target_id' => $brand->id,
            'starts_on' => '2026-01-01',
        ]);

        $this->shipment($this->base, 400_000);
        $this->shipment($this->base, 60_000, null, $focusProduct);

        $inputs = $this->collect();

        $this->assertSame(460_000.0, $inputs->baseRevenue, 'Фокусная отгрузка остаётся и в базе (п. 6.4.2)');
        $this->assertSame(60_000.0, $inputs->focusRevenue);
        $this->assertNotEmpty($inputs->focusRows);
    }

    #[Test]
    #[TestDox('Пустой фокус-перечень даёт ноль, а не всю выручку')]
    public function empty_focus_range_yields_zero(): void
    {
        $this->shipment($this->base, 400_000);

        $inputs = $this->collect();

        $this->assertSame(400_000.0, $inputs->baseRevenue);
        $this->assertSame(0.0, $inputs->focusRevenue, 'Фильтр по пустому списку товаров не ограничивает выборку — П3 стал бы равен всей выручке');
        $this->assertSame([], $inputs->focusRows);
    }

    #[Test]
    #[TestDox('Снимок перечня важнее действующих правил: прошлый месяц не переписывается')]
    public function stored_snapshot_wins_over_current_rules(): void
    {
        $brand = Brand::factory()->create();
        $inSnapshot = Product::factory()->create(['brand_id' => $brand->id]);
        $addedLater = Product::factory()->create(['brand_id' => $brand->id]);

        // Правило охватывает оба товара, но снимок июня знает только один.
        MotivationFocusRule::factory()->create([
            'scope' => MotivationFocusRule::SCOPE_BRAND,
            'target_id' => $brand->id,
            'starts_on' => '2026-01-01',
        ]);
        MotivationFocusSnapshotItem::factory()->create([
            'period_month' => '2026-06-01',
            'product_id' => $inSnapshot->id,
            'rule_id' => null,
        ]);

        $this->shipment($this->base, 30_000, null, $inSnapshot);
        $this->shipment($this->base, 70_000, null, $addedLater);

        $this->assertSame(30_000.0, $this->collect()->focusRevenue);
    }

    #[Test]
    #[TestDox('Вычет считается по дням, а не по остатку на конец месяца')]
    public function overdue_integral_is_computed_day_by_day(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        // Срок 25 мая, льгота 5 рабочих дней → просрочка с 2 июня.
        $this->ledgerInvoice($shipment, '2026-05-25');

        $inputs = $this->collect();

        // Со 2 по 30 июня — 29 дней по 100 000 ₽.
        $this->assertSame(2_900_000.0, $inputs->overdueIntegral);
        $this->assertCount(1, $inputs->overdueRows);
        $this->assertSame(29, $inputs->overdueRows[0]['days']);
    }

    #[Test]
    #[TestDox('Начисление прекращается со дня оплаты')]
    public function accrual_stops_on_the_payment_day(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        $this->ledgerInvoice($shipment, '2026-05-25', [['date' => '2026-06-12', 'amount' => 100_000]]);

        // Со 2 по 11 июня — 10 дней; день оплаты не начисляется.
        $this->assertSame(1_000_000.0, $this->collect()->overdueIntegral);
    }

    #[Test]
    #[TestDox('Частичный платёж уменьшает остаток со своего дня')]
    public function partial_payment_reduces_the_balance(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        $this->ledgerInvoice($shipment, '2026-05-25', [['date' => '2026-06-11', 'amount' => 60_000]]);

        // 2–10 июня по 100 000 (9 дней), 11–30 июня по 40 000 (20 дней).
        $this->assertSame(9 * 100_000.0 + 20 * 40_000.0, $this->collect()->overdueIntegral);
    }

    #[Test]
    #[TestDox('Исключённый долг в базу начисления не входит')]
    public function excluded_debt_is_out_of_the_accrual_base(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        $this->ledgerInvoice($shipment, '2026-05-25');

        MotivationDebtExclusion::factory()->create([
            'shipment_id' => $shipment->id,
            'user_id' => $this->base->id,
            'reason' => MotivationDebtExclusion::REASON_LEGAL,
            'excluded_from' => '2026-06-01',
        ]);

        $this->assertSame(0.0, $this->collect()->overdueIntegral);
    }

    #[Test]
    #[TestDox('Закрытое исключение возвращает долг в базу со следующего дня')]
    public function closed_exclusion_returns_the_debt_to_the_base(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        $this->ledgerInvoice($shipment, '2026-05-25');

        MotivationDebtExclusion::factory()->create([
            'shipment_id' => $shipment->id,
            'user_id' => $this->base->id,
            'excluded_from' => '2026-06-01',
            'excluded_until' => '2026-06-20',
        ]);

        // Считаются только 21–30 июня: десять дней по 100 000 ₽.
        $this->assertSame(1_000_000.0, $this->collect()->overdueIntegral);
    }

    #[Test]
    #[TestDox('Оплату, которую 1С видит, а мост не датировал, в вычет не берём')]
    public function registry_paid_without_date_is_not_overdue(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        // Зачёт 30 мая: регистр закрыл накладную, мост платежа по номеру не нашёл.
        $this->ledgerInvoice($shipment, '2026-05-25', [], registryPaid: 100_000, undatedCredits: [['date' => '2026-05-30', 'amount' => 100_000]]);

        $this->assertSame(0.0, $this->collect()->overdueIntegral, 'Незнание даты трактуется в пользу работника');
    }

    #[Test]
    #[TestDox('Просрочка партнёра не больше его долга по регистру взаиморасчётов')]
    public function overdue_is_capped_by_the_ledger_debt(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));

        // График и проекция считают накладную неоплаченной, а по ленте регистра
        // до начала июня прошёл зачёт 70 000 ₽ — долг партнёра 30 000 ₽.
        $this->ledgerInvoice($shipment, '2026-05-25', [], registryPaid: 0.0, undatedCredits: [['date' => '2026-05-28', 'amount' => 70_000]]);

        $this->assertSame(29 * 30_000.0, $this->collect()->overdueIntegral);
    }

    #[Test]
    #[TestDox('Текущий месяц считается только до сегодняшнего дня')]
    public function current_month_counts_only_until_today(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));
        $this->ledgerInvoice($shipment, '2026-05-25');

        $result = app(OverdueDebtIntegrator::class)->forMonth([$this->base->id], CarbonImmutable::parse('2026-06-01'), [], 5, CarbonImmutable::parse('2026-06-10'));

        // Со 2 по 10 июня — 9 дней; 11–30 июня ещё не наступили.
        $this->assertSame(900_000.0, $result['integral']);
        $this->assertSame('2026-06-10', $result['as_of']);
        $this->assertSame(100_000.0, $result['rows'][0]['balance_end'], 'Долг на дату расчёта — в рублях');
    }

    #[Test]
    #[TestDox('Накладная без графика оплаты в регистре в вычет не идёт')]
    public function invoice_without_registry_schedule_is_ignored(): void
    {
        $shipment = $this->shipment($this->base, 100_000, Carbon::parse('2026-05-01'));
        $this->ledgerInvoice($shipment, '2026-05-25', bridge: ['due_source' => PayrollInvoiceSettlement::DUE_SHIPMENT_COLUMN]);

        $this->assertSame(0.0, $this->collect()->overdueIntegral);
    }

    #[Test]
    #[TestDox('Отпуск уменьшает отработанные дни, замещение начисляется другому работнику')]
    public function timesheet_gives_worked_and_substitution_days(): void
    {
        $substitute = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);

        ManagerAbsence::query()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $substitute->id,
            'type' => 'vacation',
            'starts_on' => '2026-06-15',
            'ends_on' => '2026-06-30',
        ]);

        $absent = $this->collect();
        $substituting = app(MotivationInputCollector::class)->collect($substitute->id, $this->month);

        $this->assertSame(21, $absent->workingDaysTotal, 'В июне 2026 — 21 рабочий день');
        $this->assertSame(9, $absent->workedDays);
        $this->assertTrue($absent->hasAbsence());
        $this->assertSame(0, $absent->substitutionDays, 'Отсутствующему замещение не начисляется');
        $this->assertSame(12, $substituting->substitutionDays, 'Замещающему — рабочие дни отпуска коллеги');
    }

    #[Test]
    #[TestDox('План уменьшается пропорционально отработанным дням')]
    public function plan_is_reduced_by_worked_days(): void
    {
        ManagerAbsence::query()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => null,
            'type' => 'vacation',
            'starts_on' => '2026-06-15',
            'ends_on' => '2026-06-30',
        ]);

        $inputs = $this->collect();

        $this->assertEqualsWithDelta(6_000_000 * 9 / 21, $inputs->reducedPlan(6_000_000.0), 0.01);
        $this->assertSame(6_000_000.0, (new \App\Services\Motivation\Dto\MotivationInputs)->reducedPlan(6_000_000.0), 'Без табеля план не трогаем');
    }

    #[Test]
    #[TestDox('Возвраты периода уменьшают ту группу, к которой отнесён партнёр')]
    public function returns_reduce_the_group_of_their_partner(): void
    {
        MotivationPartnerNovelty::factory()->withinNovelty('2026-05-01', '2026-10-31')
            ->create(['user_id' => $this->newcomer->id]);

        $this->shipment($this->base, 400_000);
        $this->shipment($this->newcomer, 150_000);

        $this->productReturn($this->base, 25_000, ReturnStatus::COMPLETED);
        $this->productReturn($this->newcomer, 90_000, ReturnStatus::PENDING_APPROVAL);

        $inputs = $this->collect();

        $this->assertSame(375_000.0, $inputs->baseRevenue);
        $this->assertSame(150_000.0, $inputs->newPartnersRevenue, 'Неоформленный возврат показатель не уменьшает');
        $this->assertSame(25_000.0, $inputs->returns['base']);
    }

    #[Test]
    #[TestDox('Заполненный реестр закрепления важнее текущего значения в карточке партнёра')]
    public function assignment_registry_wins_over_current_attribution(): void
    {
        // Партнёр числится за менеджером в карточке, но реестр отдал его другому.
        MotivationPartnerAssignment::factory()->create([
            'user_id' => $this->base->id,
            'personal_manager_id' => PersonalManager::factory()->create()->id,
            'starts_on' => '2026-06-01',
        ]);
        MotivationPartnerAssignment::factory()->create([
            'user_id' => $this->newcomer->id,
            'personal_manager_id' => $this->manager->id,
            'starts_on' => '2026-06-01',
        ]);

        $this->shipment($this->base, 400_000);
        $this->shipment($this->newcomer, 150_000);

        $this->assertSame(150_000.0, $this->collect()->baseRevenue, 'Отгрузки переданного партнёра ушли вместе с ним');
    }
}
