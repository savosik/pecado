<?php

namespace Tests\Feature\User;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Оплаты в личном кабинете.
 *
 * Гейт здесь простой и жёсткий — прямое сравнение user_id, как у отгрузок.
 * Платёж это чужие деньги, и «увидел, зная ID» недопустимо ни при каких
 * настройках ролей.
 */
class PaymentsCabinetTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create();

        // Раздел закрыт флагом по умолчанию (цифры долга не сверены с 1С).
        // Тесты самого раздела включают его явно; выключенное состояние
        // проверяется отдельно — см. finance_flag_*.
        config(['cabinet.finance_enabled' => true]);
    }

    private function paymentFor(User $user, array $attributes = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'user_id' => $user->id,
            'number' => '29УТ-002488',
            'amount' => 2325.20,
            'currency_code' => 'RUB',
            'allocated_amount' => 0,
            'unallocated_amount' => 2325.20,
        ], $attributes));
    }

    #[Test]
    public function client_sees_only_own_payments(): void
    {
        $this->paymentFor($this->client, ['number' => 'СВОЙ']);
        $this->paymentFor(User::factory()->create(), ['number' => 'ЧУЖОЙ']);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Cabinet/Payments/Index')
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'СВОЙ'));
    }

    #[Test]
    public function foreign_payment_card_is_forbidden(): void
    {
        $foreign = $this->paymentFor(User::factory()->create());

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.show', $foreign->id))
            ->assertForbidden();
    }

    #[Test]
    public function payment_card_shows_allocations_and_advance(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_number' => '29УТ-003413',
            'total_amount' => 5000,
            'currency_code' => 'RUB',
            'paid_amount' => 1200,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);

        $payment = $this->paymentFor($this->client, [
            'amount' => 2000,
            'allocated_amount' => 1200,
            'unallocated_amount' => 800,
        ]);

        PaymentAllocation::factory()->forShipment($shipment)->create([
            'payment_id' => $payment->id,
            'amount' => 1200,
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.show', $payment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Cabinet/Payments/Show')
                ->where('payment.unallocated_amount', 800)
                ->has('payment.allocations', 1)
                ->where('payment.allocations.0.shipment.number', '29УТ-003413')
                ->where('payment.allocations.0.shipment.payment_status_label', 'Оплачена частично'));
    }

    #[Test]
    public function shipments_list_shows_payment_status_and_supports_filter(): void
    {
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_number' => 'ОПЛАЧЕНА',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'payment_status' => Shipment::PAYMENT_PAID,
        ]);
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_number' => 'НЕ-ОПЛАЧЕНА',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('shipments.data', 2)
                ->has('paymentStatuses', 4));

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.index', ['payment_status' => ['unpaid']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('shipments.data', 1)
                ->where('shipments.data.0.number', 'НЕ-ОПЛАЧЕНА')
                ->where('shipments.data.0.payment_status_label', 'Не оплачена')
                ->where('shipments.data.0.unpaid_amount', 2000));
    }

    #[Test]
    public function shipment_card_shows_related_payments(): void
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

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shipment.payment_status_label', 'Оплачена частично')
                ->where('shipment.unpaid_amount', 3800)
                ->has('shipment.payments', 1)
                ->where('shipment.payments.0.number', '29УТ-002488'));
    }

    #[Test]
    public function export_is_gated_by_feature_flag(): void
    {
        $this->paymentFor($this->client);

        config(['search-cabinet.export' => false]);
        $this->actingAs($this->client)
            ->get(route('cabinet.payments.export', ['format' => 'xlsx']))
            ->assertNotFound();

        config(['search-cabinet.export' => true]);
        $this->actingAs($this->client)
            ->get(route('cabinet.payments.export', ['format' => 'xlsx']))
            ->assertOk();

        // Формат вне белого списка не должен молча отдавать что-то другое.
        $this->actingAs($this->client)
            ->get(route('cabinet.payments.export', ['format' => 'pdf']))
            ->assertStatus(422);
    }

    #[Test]
    public function search_finds_payment_by_shipment_number(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_number' => '29УТ-777777',
        ]);

        $payment = $this->paymentFor($this->client, ['number' => 'НУЖНЫЙ']);
        PaymentAllocation::factory()->forShipment($shipment)->create(['payment_id' => $payment->id]);

        $this->paymentFor($this->client, ['number' => 'ЛИШНИЙ']);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.index', ['search' => '29УТ-777777']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'НУЖНЫЙ'));
    }

    /**
     * Строка графика по реализации клиента.
     */
    private function scheduleFor(User $user, string $dueDate, float $amount, float $paid = 0.0, float $prepaid = 0.0): ShipmentPaymentSchedule
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $user->id,
            'erp_number' => '29УТ-00'.random_int(1000, 9999),
            'currency_code' => 'RUB',
        ]);

        return ShipmentPaymentSchedule::factory()->forShipment($shipment)->create([
            'due_date' => $dueDate,
            'amount' => $amount,
            'paid_amount' => $paid,
            'prepaid_amount' => $prepaid,
        ]);
    }

    /**
     * Аванс по заказу закрывает строку наравне с прямым разнесением.
     *
     * Клиенту без разницы, каким документом 1С зачла деньги, а расчёт без
     * `prepaid_amount` показывал бы ему просрочку, которой нет: 1С разносит
     * на заказы почти половину поступлений.
     */
    #[Test]
    public function order_prepayment_closes_calendar_line(): void
    {
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 2000.00, prepaid: 2000.00);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.week_amount', 0)
                ->where('entries.0.is_paid', true)
                ->where('entries.0.unpaid_amount', 0));
    }

    /**
     * Закрытая строка с прошедшей датой — не просрочка.
     *
     * Счётчик считал все прошедшие строки подряд, включая оплаченные, и клиент
     * видел «Просрочено: 5 документов» на нулевую сумму.
     */
    #[Test]
    public function order_prepayment_is_not_counted_as_overdue(): void
    {
        $this->scheduleFor($this->client, now()->subMonth()->toDateString(), 5000.00, prepaid: 5000.00);
        $this->scheduleFor($this->client, now()->subMonth()->toDateString(), 4000.00, paid: 4000.00);
        $this->scheduleFor($this->client, now()->subMonth()->toDateString(), 3000.00);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.overdue_amount', 3000)
                ->where('summary.overdue_count', 1)
                ->has('overdueEntries', 1));
    }

    #[Test]
    public function calendar_shows_only_own_schedule_v15_12(): void
    {
        $month = now()->format('Y-m');
        $this->scheduleFor($this->client, now()->startOfMonth()->addDays(5)->toDateString(), 3000.00);
        $this->scheduleFor(User::factory()->create(), now()->startOfMonth()->addDays(5)->toDateString(), 9999.00);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar', ['month' => $month]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Cabinet/Payments/Calendar')
                ->has('entries', 1)
                ->where('entries.0.unpaid_amount', 3000));
    }

    #[Test]
    public function calendar_summary_splits_overdue_week_and_month_v15_12(): void
    {
        // Просрочка — прошлый месяц: в entries текущего месяца её быть не должно,
        // а в плитке «Просрочено» — должна, где бы клиент ни находился.
        $this->scheduleFor($this->client, now()->subMonth()->startOfMonth()->toDateString(), 1000.00);
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 2000.00);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.overdue_amount', 1000)
                ->where('summary.overdue_count', 1)
                ->where('summary.week_amount', 2000)
                ->has('overdueEntries', 1)
                ->where('overdueEntries.0.is_overdue', true));
    }

    #[Test]
    public function fully_paid_line_leaves_no_outstanding_amount_v15_12(): void
    {
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 2000.00, paid: 2000.00);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.week_amount', 0)
                ->where('entries.0.is_paid', true)
                ->where('entries.0.unpaid_amount', 0));
    }

    #[Test]
    public function calendar_falls_back_to_current_month_on_garbage_input_v15_12(): void
    {
        // Мусор в параметре не должен давать 500: календарь открывается всегда.
        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar', ['month' => 'не-месяц']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('month', now()->format('Y-m')));
    }

    #[Test]
    public function shipment_card_shows_payment_schedule_v15_12(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            'total_amount' => 10000.00,
            'payment_due_date' => now()->addDays(10)->toDateString(),
        ]);

        ShipmentPaymentSchedule::factory()->forShipment($shipment)->create([
            'due_date' => now()->addDays(10)->toDateString(),
            'amount' => 10000.00,
            'stage_name' => 'Оплата после отгрузки',
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('shipment.payment_schedule.lines', 1)
                ->where('shipment.payment_schedule.lines.0.stage_name', 'Оплата после отгрузки')
                ->where('shipment.payment_schedule.unpaid_amount', 10000)
                ->where('shipment.payment_schedule.mismatches_document', false));
    }

    #[Test]
    public function shipment_card_has_no_schedule_block_when_erp_sent_none_v15_12(): void
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shipment.payment_schedule', null));
    }

    /**
     * Выключенный флаг закрывает раздел целиком: 404, а не пустой список.
     */
    #[Test]
    public function finance_flag_hides_payments_section(): void
    {
        config(['cabinet.finance_enabled' => false]);

        $payment = $this->paymentFor($this->client);

        $this->actingAs($this->client)->get(route('cabinet.payments.index'))->assertNotFound();
        $this->actingAs($this->client)->get(route('cabinet.payments.calendar'))->assertNotFound();
        $this->actingAs($this->client)->get(route('cabinet.payments.show', $payment->id))->assertNotFound();
        $this->actingAs($this->client)->get(route('cabinet.payments.export', ['format' => 'csv']))->assertNotFound();
    }

    /**
     * Выключенный флаг убирает деньги и из карточки реализации: клиент не должен
     * видеть остаток, который мы сами ещё не сверили.
     */
    #[Test]
    public function finance_flag_hides_payment_data_in_shipment_card(): void
    {
        config(['cabinet.finance_enabled' => false]);

        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'total_amount' => 5000,
            'paid_amount' => 1000,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);

        ShipmentPaymentSchedule::factory()->forShipment($shipment)->create([
            'due_date' => now()->subDays(5)->toDateString(),
            'amount' => 5000,
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('shipment.payment_status')
                ->missing('shipment.paid_amount')
                ->missing('shipment.payments')
                ->missing('shipment.payment_schedule')
                ->where('overdue_detail', null)
            );
    }

    /**
     * Список реализаций тоже не показывает оплату — ни колонкой, ни фильтром.
     */
    #[Test]
    public function finance_flag_hides_payment_status_in_shipment_list(): void
    {
        config(['cabinet.finance_enabled' => false]);

        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.shipments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paymentStatuses', [])
                ->missing('shipments.data.0.payment_status')
            );
    }
}
