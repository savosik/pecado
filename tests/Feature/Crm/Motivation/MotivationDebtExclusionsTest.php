<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Events\Payroll\PayrollInputsChanged;
use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\DebtExclusionService;
use App\Services\Motivation\DebtListService;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Исключения задолженности и очередь разметки с ценой (карточка mot-37; формы B8, B9).
 */
class MotivationDebtExclusionsTest extends TestCase
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

        app(MotivationSchemeInstaller::class)->install($this->month);
    }

    /**
     * Накладная со сроком за N месяцев до начала периода — просрочена весь месяц.
     */
    private function overdueInvoice(User $partner, float $amount, int $dueMonthsAgo = 4, bool $needsReview = false): PayrollInvoiceSettlement
    {
        $shipped = $this->month->subMonths($dueMonthsAgo + 1)->addDays(3);

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

        return PayrollInvoiceSettlement::query()->create([
            'shipment_id' => $shipment->id,
            'shipment_uuid' => $shipment->uuid,
            'user_id' => $partner->id,
            'personal_manager_id' => $this->profile->id,
            'erp_number' => $shipment->erp_number,
            'total_amount' => $amount,
            'shipped_on' => $shipped->toDateString(),
            'due_on' => $this->month->subMonths($dueMonthsAgo)->toDateString(),
            'needs_review' => $needsReview,
        ]);
    }

    #[Test]
    #[TestDox('Кандидаты: долги старше срока с ценой в месяц, самые дорогие сверху')]
    public function candidates_are_priced_and_sorted(): void
    {
        $old = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Старый долг']);
        $fresh = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Свежий долг']);
        $this->overdueInvoice($old, 100_000, dueMonthsAgo: 5);
        $this->overdueInvoice($old, 300_000, dueMonthsAgo: 5);
        $this->overdueInvoice($fresh, 500_000, dueMonthsAgo: 1);

        $data = app(DebtExclusionService::class)->overview(90);

        $this->assertSame(2, $data['summary']['invoices'], 'Свежий долг моложе 90 дней не кандидат');
        $this->assertSame(1, $data['summary']['partners']);
        $this->assertSame(300_000.0, $data['candidates'][0]['balance'], 'Дорогой сверху');
        $this->assertEqualsWithDelta(300_000 * 0.0005 * CarbonImmutable::today()->daysInMonth, $data['candidates'][0]['monthly_cost'], 0.01);
        $this->assertGreaterThanOrEqual(90, $data['candidates'][0]['overdue_days']);

        $this->assertSame(3, app(DebtExclusionService::class)->overview(0)['summary']['invoices']);
    }

    #[Test]
    #[TestDox('Исключение по документу снимает вычет с черновика; закрытие датой возвращает долг в расчёт')]
    public function exclusion_removes_deduction_and_can_be_closed(): void
    {
        Event::fake([PayrollInputsChanged::class]);
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Партнёр']);
        $invoice = $this->overdueInvoice($partner, 200_000);
        $calculations = app(PayrollCalculationService::class);

        $before = app(DebtListService::class)->build($calculations->ensureDraft($this->profile->id, $this->month));
        $this->assertGreaterThan(0, $before['summary']['deducted_this_month']);

        $exclusion = app(DebtExclusionService::class)->create([
            'reason' => 'legal',
            'excluded_from' => $this->month->toDateString(),
            'document_ref' => 'Претензия № 7',
            'shipment_id' => $invoice->shipment_id,
        ], $this->head);

        Event::assertDispatched(PayrollInputsChanged::class, fn (PayrollInputsChanged $e) => $e->source === 'debt.exclusion' && $e->managerIds === [$this->profile->id]);
        $this->assertSame($partner->id, (int) $exclusion->user_id, 'Партнёр взят из накладной');

        $after = app(DebtListService::class)->build($calculations->recalculateDraft($this->profile->id, $this->month, 'test'));
        $this->assertSame(0.0, $after['summary']['deducted_this_month'], 'Вычет по исключённому долгу не начисляется');
        $this->assertSame(1, $after['summary']['excluded_count']);
        $this->assertSame('передан в претензионную работу', $after['excluded'][0]['reason_label']);

        try {
            app(DebtExclusionService::class)->create(['reason' => 'legal', 'excluded_from' => $this->month->toDateString(), 'document_ref' => 'Ещё раз', 'shipment_id' => $invoice->shipment_id], $this->head);
            $this->fail('Повторное исключение того же долга');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('уже действует исключение', $e->getMessage());
        }

        app(DebtExclusionService::class)->close($exclusion, $this->month->addDays(9));
        $reopened = app(DebtListService::class)->build($calculations->recalculateDraft($this->profile->id, $this->month, 'test'));
        $this->assertGreaterThan(0, $reopened['summary']['deducted_this_month'], 'После возврата долг снова в расчёте');
        $this->assertLessThan($before['summary']['deducted_this_month'], $reopened['summary']['deducted_this_month'], 'Но за дни исключения вычета нет');
        $this->assertSame(1, MotivationDebtExclusion::query()->count(), 'Закрытие — датой, не удалением');
    }

    #[Test]
    #[TestDox('Очередь разметки: колонка «в расчёте», сортировка по цене, простановка даты через проектор')]
    public function review_queue_prices_invoices(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Партнёр']);
        $cheap = $this->overdueInvoice($partner, 10_000, needsReview: true);
        $costly = $this->overdueInvoice($partner, 400_000, needsReview: true);
        $this->overdueInvoice($partner, 999_000, needsReview: false);

        $this->actingAs($this->head)
            ->get('/crm/motivation/invoices')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Invoices')
                ->where('summary.total', 2)
                ->has('rows.data', 2)
                ->where('rows.data.0.id', $costly->id)
                ->where('rows.data.1.id', $cheap->id)
                ->where('rows.data.0.k1_cost', fn ($v) => $v > 0));

        $this->actingAs($this->head)
            ->patchJson("/crm/motivation/invoices/{$costly->id}", ['settled_on' => $this->month->subMonths(3)->toDateString(), 'comment' => 'Зачёт по письму'])
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('rows.data.0.id', $cheap->id);

        $this->assertNotNull($costly->fresh()->manual_settled_on);
    }

    #[Test]
    #[TestDox('Страница исключений: API исключения и возврата; менеджеру раздел закрыт')]
    public function page_and_endpoints(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Партнёр']);
        $invoice = $this->overdueInvoice($partner, 150_000);

        $this->actingAs($this->head)
            ->get('/crm/motivation/debt-exclusions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/DebtExclusions')
                ->where('older_than_days', 90)
                ->has('candidates', 1)
                ->where('can_edit', true));

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/debt-exclusions', ['reason' => 'written_off', 'excluded_from' => $this->month->toDateString(), 'shipment_id' => $invoice->shipment_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_ref']);

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/debt-exclusions', ['reason' => 'written_off', 'excluded_from' => $this->month->toDateString(), 'document_ref' => 'Приказ 3-с', 'user_id' => $partner->id])
            ->assertOk()
            ->assertJsonPath('exclusions.0.number', null)
            ->assertJsonPath('exclusions.0.active', true)
            ->assertJsonCount(0, 'candidates');

        $id = MotivationDebtExclusion::query()->value('id');
        $this->actingAs($this->head)
            ->patchJson("/crm/motivation/debt-exclusions/{$id}", ['excluded_until' => $this->month->subDay()->toDateString()])
            ->assertUnprocessable();
        $this->actingAs($this->head)
            ->patchJson("/crm/motivation/debt-exclusions/{$id}", ['excluded_until' => CarbonImmutable::today()->toDateString()])
            ->assertOk()
            ->assertJsonPath('exclusions.0.excluded_until', CarbonImmutable::today()->toDateString());

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/debt-exclusions')->assertForbidden();
        $this->actingAs($manager)->get('/crm/motivation/invoices')->assertForbidden();
    }
}
