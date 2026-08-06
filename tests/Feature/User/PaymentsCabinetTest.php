<?php

namespace Tests\Feature\User;

use App\Models\Payment;
use App\Models\PaymentAllocation;
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
}
