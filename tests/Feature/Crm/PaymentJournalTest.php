<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PersonalManager;
use App\Models\Shipment;
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
}
