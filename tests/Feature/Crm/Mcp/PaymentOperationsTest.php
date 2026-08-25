<?php

namespace Tests\Feature\Crm\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\Crm\CrmCall;
use App\Mcp\Tools\Crm\CrmCatalog;
use App\Models\Payment;
use App\Models\PersonalManager;
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
        ], $attributes));
    }

    #[Test]
    #[TestDox('Каталог отдаёт секцию платежей')]
    public function catalog_exposes_payments_section(): void
    {
        $response = CrmServer::actingAs($this->managerA)->tool(CrmCatalog::class, ['section' => 'payments']);

        $response->assertOk();
        $response->assertSee('payment.list');
        // Снятые в fin-11 операции: на вопрос про долг отвечает settlement.*
        $response->assertDontSee('payment.unpaid-shipments');
        $response->assertDontSee('payment.balances');
        $response->assertDontSee('payment.schedule');
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
}
