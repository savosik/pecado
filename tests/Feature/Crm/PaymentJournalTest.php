<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Журнал платежей и карточка платежа в CRM.
 *
 * Отдельного права у платежей нет — доступ решает тот же скоуп клиентов, что
 * и у заказов с реализациями. Поэтому изоляция проверяется на каждом входе:
 * платёж это ещё один способ добраться до чужих денег, зная ID.
 */
class PaymentJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $card;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    private function foreignClient(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    private function paymentFor(User $client, array $attributes = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'user_id' => $client->id,
            'number' => '29УТ-002488',
            'amount' => 2325.20,
            'currency_code' => 'RUB',
            'allocated_amount' => 0,
            'unallocated_amount' => 2325.20,
        ], $attributes));
    }

    #[Test]
    public function manager_sees_only_payments_of_own_clients(): void
    {
        $this->paymentFor($this->client, ['number' => 'СВОЙ-1']);
        $this->paymentFor($this->foreignClient(), ['number' => 'ЧУЖОЙ-1']);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Documents/Payments')
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'СВОЙ-1'));
    }

    #[Test]
    public function foreign_payment_card_returns_404_not_403(): void
    {
        $foreign = $this->paymentFor($this->foreignClient());

        // 403 подтвердил бы менеджеру существование чужого клиента.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.show', $foreign->id))
            ->assertNotFound();
    }

    #[Test]
    public function payment_card_shows_details_summary_and_allocations(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_number' => '29УТ-003413',
            'total_amount' => 5000,
            'currency_code' => 'RUB',
        ]);

        $payment = $this->paymentFor($this->client, [
            'amount' => 2000,
            'allocated_amount' => 1200,
            'unallocated_amount' => 800,
            'document_type' => 'Платежное поручение',
            'bank_number' => '9202',
        ]);

        PaymentAllocation::factory()->forShipment($shipment)->create([
            'payment_id' => $payment->id,
            'amount' => 1200,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.show', $payment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Documents/Show')
                ->where('document.type', 'payment')
                ->has('document.items', 1)
                ->has('document.related', 1)
                ->has('document.summary', 3)
                // Реквизиты платёжного поручения — блок, которого нет у заказов.
                ->where('document.details.0.label', 'Тип документа')
                ->where('document.details.0.value', 'Платежное поручение'));
    }

    #[Test]
    public function shipment_card_shows_payments_and_summary(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'total_amount' => 5000,
            'currency_code' => 'RUB',
            'paid_amount' => 1200,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);

        $payment = $this->paymentFor($this->client);
        PaymentAllocation::factory()->forShipment($shipment)->create([
            'payment_id' => $payment->id,
            'amount' => 1200,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.payment_summary.status', Shipment::PAYMENT_PARTIAL)
                ->where('document.payment_summary.status_label', 'Оплачена частично')
                ->has('document.payments', 1)
                ->where('document.payments.0.number', '29УТ-002488'));
    }

    #[Test]
    public function direction_and_allocation_filters_narrow_the_journal(): void
    {
        $this->paymentFor($this->client, ['number' => 'ПРИХОД', 'allocated_amount' => 2325.20, 'unallocated_amount' => 0]);
        $this->paymentFor($this->client, ['number' => 'ВОЗВРАТ', 'direction' => Payment::DIRECTION_OUT]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['directions' => ['out']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'ВОЗВРАТ'));

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['allocation_statuses' => ['advance']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'ВОЗВРАТ'));
    }

    #[Test]
    public function legacy_scalar_direction_parameter_still_filters(): void
    {
        $this->paymentFor($this->client, ['number' => 'ПРИХОД']);
        $this->paymentFor($this->client, ['number' => 'ВОЗВРАТ', 'direction' => Payment::DIRECTION_OUT]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['direction' => 'out']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('payments.data', 1));
    }

    #[Test]
    public function filter_options_are_built_from_payments_not_from_all_clients(): void
    {
        // У клиента платежей нет — в справочнике партнёров его быть не должно:
        // у РОПа клиентов сотни, и список фильтра собирается из самого журнала.
        $withoutPayments = User::factory()->create(['personal_manager_id' => $this->card->id]);

        $company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->paymentFor($this->client, ['company_id' => $company->id]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($withoutPayments) {
                $partnerIds = array_column($page->toArray()['props']['partners'], 'id');

                $this->assertContains($this->client->id, $partnerIds);
                $this->assertNotContains($withoutPayments->id, $partnerIds);
            });
    }

    #[Test]
    public function xlsx_export_uses_the_same_scope_as_the_screen(): void
    {
        $this->paymentFor($this->client, ['number' => 'СВОЙ-1']);
        $this->paymentFor($this->foreignClient(), ['number' => 'ЧУЖОЙ-1']);

        $response = $this->actingAs($this->manager)->get(route('crm.payments.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    /**
     * Строка графика по реализации клиента.
     */
    private function scheduleFor(User $client, string $dueDate, float $amount, float $paid = 0.0): ShipmentPaymentSchedule
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $client->id,
            'erp_number' => '29УТ-00'.random_int(1000, 9999),
            'currency_code' => 'RUB',
        ]);

        return ShipmentPaymentSchedule::factory()->forShipment($shipment)->create([
            'due_date' => $dueDate,
            'amount' => $amount,
            'paid_amount' => $paid,
        ]);
    }

    #[Test]
    public function calendar_is_limited_to_manager_client_scope_v15_12(): void
    {
        $inMonth = now()->startOfMonth()->addDays(10)->toDateString();
        $this->scheduleFor($this->client, $inMonth, 3000.00);
        $this->scheduleFor($this->foreignClient(), $inMonth, 9999.00);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Documents/PaymentCalendar')
                ->has('entries', 1)
                ->where('entries.0.unpaid_amount', 3000)
                ->where('summary.plan_month', 3000));
    }

    #[Test]
    public function calendar_counts_plan_and_fact_separately_v15_12(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->scheduleFor($this->client, $day->toDateString(), 3000.00);
        $this->paymentFor($this->client, [
            'amount' => 1000.00,
            'direction' => Payment::DIRECTION_IN,
            'date' => $day->copy()->setTime(12, 0),
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // План идёт по графику, факт — по проведённым платежам:
                // одно не подменяет другое даже в один и тот же день.
                ->where('summary.plan_month', 3000)
                ->where('summary.fact_month', 1000)
                ->where('facts.'.$day->toDateString().'.amount', 1000));
    }

    #[Test]
    public function refund_reduces_the_fact_of_the_day_v15_12(): void
    {
        $day = now()->startOfMonth()->addDays(10);

        $this->paymentFor($this->client, [
            'amount' => 1000.00,
            'direction' => Payment::DIRECTION_IN,
            'date' => $day->copy()->setTime(10, 0),
        ]);
        $this->paymentFor($this->client, [
            'amount' => 400.00,
            'direction' => Payment::DIRECTION_OUT,
            'date' => $day->copy()->setTime(15, 0),
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.fact_month', 600));
    }

    #[Test]
    public function closed_schedule_lines_are_not_expected_money_v15_12(): void
    {
        $inMonth = now()->startOfMonth()->addDays(10)->toDateString();
        $this->scheduleFor($this->client, $inMonth, 3000.00, paid: 3000.00);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 0)
                ->where('summary.plan_month', 0));
    }

    #[Test]
    public function overdue_is_shown_regardless_of_displayed_month_v15_12(): void
    {
        $this->scheduleFor($this->client, now()->subMonth()->startOfMonth()->toDateString(), 1500.00);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 0)
                ->has('overdueEntries', 1)
                ->where('overdueEntries.0.is_overdue', true)
                ->where('summary.overdue_amount', 1500));
    }

    #[Test]
    public function shipment_card_shows_payment_schedule_v15_12(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            'total_amount' => 10000.00,
        ]);

        ShipmentPaymentSchedule::factory()->forShipment($shipment)->create([
            'due_date' => now()->addDays(10)->toDateString(),
            'amount' => 10000.00,
            'stage_name' => 'Оплата после отгрузки',
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('document.payment_schedule.lines', 1)
                ->where('document.payment_schedule.lines.0.status_label', 'Ожидается'));
    }
}
