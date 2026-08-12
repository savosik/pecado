<?php

namespace Tests\Feature\Crm\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\Crm\CrmCall;
use App\Mcp\Tools\Crm\CrmCatalog;
use App\Models\ContractorBalance;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Платежи через MCP отдела продаж.
 *
 * Операции только читающие, но читают они деньги, поэтому проверяется главное:
 * скоуп задаёт актор, а не аргумент вызова — чужой client_id не расширяет
 * видимость, а чужой платёж по id не открывается.
 */
class PaymentOperationsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $managerA;

    private User $clientA;

    private User $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->managerA = User::factory()->create();
        $this->managerA->assignRole('sales-manager');
        $cardA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->clientA = User::factory()->create(['personal_manager_id' => $cardA->id]);
        $this->clientB = User::factory()->create([
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
    #[TestDox('Каталог отдаёт секцию платежей')]
    public function catalog_exposes_payments_section(): void
    {
        $response = CrmServer::actingAs($this->managerA)->tool(CrmCatalog::class, ['section' => 'payments']);

        $response->assertOk();
        $response->assertSee('payment.list');
        $response->assertSee('payment.unpaid-shipments');
        $response->assertSee('payment.balances');
    }

    #[Test]
    #[TestDox('Список платежей ограничен клиентами актора')]
    public function list_is_limited_to_actor_scope(): void
    {
        $this->paymentFor($this->clientA, ['number' => 'СВОЙ']);
        $this->paymentFor($this->clientB, ['number' => 'ЧУЖОЙ']);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.list', 'arguments' => []]);

        $response->assertOk();
        $response->assertSee('СВОЙ');
        $response->assertDontSee('ЧУЖОЙ');
    }

    #[Test]
    #[TestDox('Чужой client_id не расширяет видимость')]
    public function foreign_client_id_does_not_widen_scope(): void
    {
        $this->paymentFor($this->clientB, ['number' => 'ЧУЖОЙ']);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'payment.list',
                'arguments' => ['client_id' => $this->clientB->id],
            ])
            ->assertHasErrors();
    }

    #[Test]
    #[TestDox('Чужой платёж по id не открывается')]
    public function foreign_payment_is_not_accessible_by_id(): void
    {
        $foreign = $this->paymentFor($this->clientB);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'payment.show',
                'arguments' => ['payment' => $foreign->id],
            ])
            ->assertHasErrors();
    }

    #[Test]
    #[TestDox('Фильтр авансов отбирает платежи с остатком')]
    public function only_unallocated_filter_returns_advances(): void
    {
        $this->paymentFor($this->clientA, [
            'number' => 'РАЗНЕСЁН',
            'allocated_amount' => 2325.20,
            'unallocated_amount' => 0,
        ]);
        $this->paymentFor($this->clientA, ['number' => 'АВАНС']);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'payment.list',
                'arguments' => ['only_unallocated' => true],
            ]);

        $response->assertOk();
        $response->assertSee('АВАНС');
        $response->assertDontSee('РАЗНЕСЁН');
    }

    #[Test]
    #[TestDox('Неоплаченные отгрузки отдаются одним вызовом')]
    public function unpaid_shipments_are_returned_in_one_call(): void
    {
        Shipment::factory()->create([
            'user_id' => $this->clientA->id,
            'erp_number' => 'ДОЛГ',
            'total_amount' => 5000,
            'paid_amount' => 1200,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);
        Shipment::factory()->create([
            'user_id' => $this->clientA->id,
            'erp_number' => 'ЗАКРЫТА',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'payment_status' => Shipment::PAYMENT_PAID,
        ]);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.unpaid-shipments', 'arguments' => []]);

        $response->assertOk();
        $response->assertSee('ДОЛГ');
        $response->assertDontSee('ЗАКРЫТА');
    }

    #[Test]
    #[TestDox('Карточка платежа показывает расшифровку')]
    public function payment_card_returns_allocations(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->clientA->id,
            'erp_number' => '29УТ-003413',
            'total_amount' => 5000,
        ]);

        $payment = $this->paymentFor($this->clientA);
        PaymentAllocation::factory()->forShipment($shipment)->create([
            'payment_id' => $payment->id,
            'amount' => 1200,
        ]);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'payment.show',
                'arguments' => ['payment' => $payment->id],
            ]);

        $response->assertOk();
        $response->assertSee('29УТ-003413');
    }

    /**
     * Реализация клиента со строкой графика оплаты.
     */
    private function scheduleFor(User $client, string $number, string $dueDate, float $amount, float $paid = 0.0): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $client->id,
            'erp_number' => $number,
            'currency_code' => 'RUB',
        ]);

        \App\Models\ShipmentPaymentSchedule::factory()->forShipment($shipment)->create([
            'due_date' => $dueDate,
            'amount' => $amount,
            'paid_amount' => $paid,
        ]);
    }

    #[Test]
    #[TestDox('График оплаты ограничен клиентами актора')]
    public function schedule_is_limited_to_actor_scope(): void
    {
        $this->scheduleFor($this->clientA, 'СВОЙ-ГРАФИК', now()->addDays(10)->toDateString(), 3000.00);
        $this->scheduleFor($this->clientB, 'ЧУЖОЙ-ГРАФИК', now()->addDays(10)->toDateString(), 9999.00);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.schedule', 'arguments' => []]);

        $response->assertOk();
        $response->assertSee('СВОЙ-ГРАФИК');
        $response->assertDontSee('ЧУЖОЙ-ГРАФИК');
    }

    #[Test]
    #[TestDox('Закрытые строки графика в ожидаемые деньги не попадают')]
    public function schedule_skips_closed_lines(): void
    {
        $this->scheduleFor($this->clientA, 'ЖДЁМ', now()->addDays(10)->toDateString(), 3000.00);
        $this->scheduleFor($this->clientA, 'ПОЛУЧЕНО', now()->addDays(10)->toDateString(), 5000.00, paid: 5000.00);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.schedule', 'arguments' => []]);

        $response->assertOk();
        $response->assertSee('ЖДЁМ');
        $response->assertDontSee('ПОЛУЧЕНО');
    }

    #[Test]
    #[TestDox('Фильтр only_overdue оставляет только просроченные строки')]
    public function schedule_filters_overdue_only(): void
    {
        $this->scheduleFor($this->clientA, 'ПРОСРОЧЕНО', now()->subDays(10)->toDateString(), 1000.00);
        $this->scheduleFor($this->clientA, 'ВПЕРЕДИ', now()->addDays(10)->toDateString(), 2000.00);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'payment.schedule',
                'arguments' => ['only_overdue' => true],
            ]);

        $response->assertOk();
        $response->assertSee('ПРОСРОЧЕНО');
        $response->assertDontSee('ВПЕРЕДИ');
    }

    #[Test]
    #[TestDox('Период отбирается по плановой дате платежа')]
    public function schedule_filters_by_due_date_range(): void
    {
        $this->scheduleFor($this->clientA, 'В-ПЕРИОДЕ', '2026-09-15', 1000.00);
        $this->scheduleFor($this->clientA, 'ВНЕ-ПЕРИОДА', '2026-11-15', 2000.00);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'payment.schedule',
                'arguments' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'],
            ]);

        $response->assertOk();
        $response->assertSee('В-ПЕРИОДЕ');
        $response->assertDontSee('ВНЕ-ПЕРИОДА');
    }

    #[Test]
    #[TestDox('Балансы 1С ограничены клиентами актора')]
    public function balances_are_limited_to_actor_scope(): void
    {
        ContractorBalance::create([
            'user_id' => $this->clientA->id,
            'tax_id' => '7701111111',
            'current_balance' => -1500,
            'overdue_debt' => 600,
        ]);

        ContractorBalance::create([
            'user_id' => $this->clientB->id,
            'tax_id' => '7702222222',
            'current_balance' => -9999,
            'overdue_debt' => 9999,
        ]);

        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.balances', 'arguments' => []]);

        $response->assertOk();
        $response->assertSee('7701111111');
        $response->assertDontSee('7702222222');
    }

    /**
     * Агент читает ответ, а не описание инструмента: подсказка про источник долга
     * должна ехать вместе с данными, иначе остаток по документам примут за долг.
     */
    #[Test]
    #[TestDox('Ответы по деньгам несут пояснение об источнике данных')]
    public function money_responses_carry_source_notes(): void
    {
        Shipment::factory()->create([
            'user_id' => $this->clientA->id,
            'payment_status' => Shipment::PAYMENT_UNPAID,
            'total_amount' => 1000,
            'paid_amount' => 0,
        ]);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.unpaid-shipments', 'arguments' => []])
            ->assertOk()
            ->assertSee('payment.balances');

        ContractorBalance::create([
            'user_id' => $this->clientA->id,
            'tax_id' => '7701111111',
            'current_balance' => -1500,
            'overdue_debt' => 600,
        ]);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'payment.balances', 'arguments' => []])
            ->assertOk()
            ->assertSee('Мастер-данные');
    }
}
