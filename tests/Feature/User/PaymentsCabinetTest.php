<?php

namespace Tests\Feature\User;

use App\Models\Payment;
use App\Models\SettlementEntry;
use App\Models\Shipment;
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

    /**
     * У клиента бывает несколько юрлиц-контрагентов; платежи сверяются
     * по каждому отдельно.
     */
    #[Test]
    public function payments_filter_by_company(): void
    {
        $first = \App\Models\Company::factory()->create(['user_id' => $this->client->id]);
        $second = \App\Models\Company::factory()->create(['user_id' => $this->client->id]);

        $this->paymentFor($this->client, ['number' => 'ПЕРВЫЙ', 'company_id' => $first->id]);
        $this->paymentFor($this->client, ['number' => 'ВТОРОЙ', 'company_id' => $second->id]);

        $this->actingAs($this->client)
            ->get(route('cabinet.payments.index', ['company_id' => $first->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'ПЕРВЫЙ')
                // Снимок отбора обязан вернуть выбор — иначе селект на фронте
                // рендерится пустым и фильтр «слетает» следующим запросом.
                ->where('filters.company_id', (string) $first->id)
                ->has('companies', 2));
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

    /**
     * Плановая строка регистра по реализации клиента.
     *
     * Регистр не делит погашение на «разнесено» и «зачтено авансом»:
     * 1С отдаёт одну закрытую часть, поэтому оба слагаемых складываются.
     */
    private function scheduleFor(User $user, string $dueDate, float $amount, float $paid = 0.0, float $prepaid = 0.0): SettlementEntry
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $user->id,
            'erp_number' => '29УТ-00'.random_int(1000, 9999),
            'currency_code' => 'RUB',
        ]);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $user->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'document_number' => $shipment->erp_number,
            'date' => $dueDate,
            'amount' => $amount,
            'settled_amount' => $paid + $prepaid,
            'currency_code' => 'RUB',
        ]);
    }

    #[Test]
    public function order_prepayment_closes_calendar_line(): void
    {
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 2000.00, prepaid: 2000.00);

        // Закрытая строка исчезает из календаря целиком: регистр отдаёт только
        // непогашенное, и «оплачено 0 ₽» в клиентском календаре — лишний шум.
        $this->actingAs($this->client)
            ->get(route('cabinet.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.week_amount', 0)
                ->where('entries', []));
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
                ->where('entries', []));
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

        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => now()->addDays(10)->toDateString(),
            'amount' => 10000.00,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
            'meta' => ['stage_name' => 'Оплата после отгрузки'],
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

        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => now()->subDays(5)->toDateString(),
            'amount' => 5000,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
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
