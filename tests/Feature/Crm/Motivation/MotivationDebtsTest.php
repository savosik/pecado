<?php

namespace Tests\Feature\Crm\Motivation;

use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\DebtListService;
use App\Services\Motivation\MotivationSchemeInstaller;
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
use Tests\Feature\Crm\Motivation\Concerns\RegistersInvoicesInLedger;
use Tests\TestCase;

/**
 * «Долги: во что они обходятся» (карточка mot-28).
 *
 * Главный инвариант: сумма «вычтено за месяц» по партнёрам равна показателю К1
 * расчёта. Экран и расчёт читают один снимок, и расхождение — дефект.
 */
class MotivationDebtsTest extends TestCase
{
    use RefreshDatabase;
    use RegistersInvoicesInLedger;
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
        // К1 текущего месяца считается до сегодняшнего дня: без фиксации времени
        // тесты зависели бы от числа, в которое их запустили.
        $this->travelTo($this->month->addDays(20)->setTime(12, 0));
        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);

        app(MotivationSchemeInstaller::class)->install($this->month);
    }

    /**
     * Накладная партнёра со сроком за месяц до начала периода — просрочена весь месяц.
     */
    private function overdueInvoice(User $partner, float $amount, bool $needsReview = false, ?string $settledOn = null): PayrollInvoiceSettlement
    {
        $shipped = $this->month->subMonths(2)->addDays(3);

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $partner->id,
            'date' => $shipped->toDateString(),
            'erp_created_at' => $shipped,
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

        $due = $this->month->subMonth()->toDateString();

        return $this->ledgerInvoice(
            $shipment,
            $due,
            $settledOn === null ? [] : [['date' => $settledOn, 'amount' => $amount]],
            $needsReview ? $amount : null,
            // «Оплачено по 1С, дата не найдена»: в ленте регистра зачёт есть, мост его не видит.
            $needsReview ? [['date' => $due, 'amount' => $amount]] : [],
        );
    }

    #[Test]
    #[TestDox('Сумма «вычтено за месяц» по партнёрам равна показателю К1 расчёта')]
    public function deducted_by_partner_adds_up_to_k1(): void
    {
        $a = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Альфа']);
        $b = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Бета']);
        $this->overdueInvoice($a, 300_000);
        $this->overdueInvoice($a, 120_000, needsReview: true);
        $this->overdueInvoice($b, 50_000);

        $calculation = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $debts = app(DebtListService::class)->build($calculation);

        $this->assertGreaterThan(0, $debts['summary']['deducted_this_month'], 'Просрочка весь месяц — вычет обязан быть');

        $sum = array_sum(array_column($debts['partners'], 'deducted_this_month'));
        $this->assertEqualsWithDelta($debts['summary']['deducted_this_month'], $sum, 0.01 * count($debts['partners']));

        $this->assertSame(2, $debts['summary']['partners_count']);
        // Накладная, оплаченная по 1С без найденной даты, в вычет не идёт — в пользу работника.
        $this->assertSame(2, $debts['summary']['invoices_count']);
        $this->assertSame(0, $debts['summary']['needs_review_count']);
        $this->assertSame('Альфа', $debts['partners'][0]['name'], 'Самый дорогой партнёр сверху');
        $this->assertSame(300_000.0, $debts['partners'][0]['debt']);
        $this->assertCount(1, $debts['partners'][0]['invoices']);
    }

    #[Test]
    #[TestDox('Накладная, оплаченная в середине месяца, стоит в день ноль, но вычет за дни до оплаты остаётся')]
    public function paid_invoice_stops_daily_cost_but_keeps_past_deduction(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id]);
        $this->overdueInvoice($partner, 100_000, settledOn: $this->month->addDays(9)->toDateString());

        $calculation = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $debts = app(DebtListService::class)->build($calculation);

        $invoice = $debts['partners'][0]['invoices'][0];

        $this->assertSame(0.0, $invoice['balance']);
        $this->assertSame(0.0, $invoice['daily_cost']);
        $this->assertGreaterThan(0, $invoice['deducted_this_month']);
        $this->assertSame(9, $invoice['overdue_days'], 'Начисление прекращается со дня оплаты');
    }

    #[Test]
    #[TestDox('Исключённый долг виден отдельным разделом с основанием и в базу не входит')]
    public function excluded_debt_is_listed_separately(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Спорный']);
        $invoice = $this->overdueInvoice($partner, 200_000);

        MotivationDebtExclusion::factory()->create([
            'shipment_id' => $invoice->shipment_id,
            'user_id' => $partner->id,
            'reason' => MotivationDebtExclusion::REASON_LEGAL,
            'excluded_from' => $this->month->toDateString(),
        ]);

        $calculation = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $debts = app(DebtListService::class)->build($calculation);

        $this->assertSame([], $debts['partners'], 'В базе начисления долга нет');
        $this->assertSame(0.0, $debts['summary']['deducted_this_month']);
        $this->assertCount(1, $debts['excluded']);
        $this->assertSame('передан в претензионную работу', $debts['excluded'][0]['reason_label']);
        $this->assertSame('Спорный', $debts['excluded'][0]['partner_name']);
    }

    #[Test]
    #[TestDox('Утверждённый месяц читает состав вычета из снимка')]
    public function frozen_month_reads_the_snapshot(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id]);
        $this->overdueInvoice($partner, 100_000);

        $service = app(PayrollCalculationService::class);
        $draft = $service->ensureDraft($this->profile->id, $this->month);
        $service->approve($draft, $this->head);

        // После утверждения долг «погасили» — снимок этого не видит, и это правильно.
        PayrollInvoiceSettlement::query()->update(['settled_on' => $this->month->toDateString()]);

        $debts = app(DebtListService::class)->build($draft->refresh());

        $this->assertTrue($debts['frozen']);
        $this->assertCount(1, $debts['partners']);
        $this->assertGreaterThan(0, $debts['summary']['deducted_this_month']);
    }

    #[Test]
    #[TestDox('Страница открывается руководителю, закрыта менеджеру без права')]
    public function page_respects_permission(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation/debts?partner=5')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Debts')
                ->where('focus_partner', 5)
                ->has('debts.summary')
                ->has('debts.partners'));

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/debts')->assertForbidden();
    }
}
