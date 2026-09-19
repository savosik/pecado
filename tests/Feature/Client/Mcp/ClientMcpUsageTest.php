<?php

namespace Tests\Feature\Client\Mcp;

use App\Mcp\Servers\ClientServer;
use App\Mcp\Tools\Client\ClientCatalog;
use App\Models\ApiToken;
use App\Models\ClientAgentCall;
use App\Models\Company;
use App\Models\User;
use App\Support\Client\ClientApiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Журнал вызовов агентов клиентов (`client_agent_calls`, capi-17).
 *
 * Проверяется то, ради чего журнал заведён: подключение агента запоминает, кто
 * он; каждый инструмент оставляет строку с операцией и исходом; REST v1 пишет в
 * тот же журнал; и сбой самого журнала не ломает вызов клиента.
 */
class ClientMcpUsageTest extends TestCase
{
    use RefreshDatabase;

    private const MCP = '/mcp/client';

    private User $client;

    private ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['name' => 'Клиент с агентом']);
        Company::factory()->create(['user_id' => $this->client->id, 'is_default' => true, 'tax_id' => '7707083893']);
        $this->token = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Агент клиента', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        ClientApiSource::reset();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function rpc(array $body, ?string $session = null): \Illuminate\Testing\TestResponse
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$this->token->token,
        ];

        if ($session !== null) {
            $headers['MCP-Session-Id'] = $session;
        }

        return $this->postJson(self::MCP, ['jsonrpc' => '2.0'] + $body, $headers);
    }

    private function initialize(): string
    {
        $response = $this->rpc([
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'claude-code', 'version' => '2.1.0'],
            ],
        ])->assertOk();

        $session = $response->headers->get('MCP-Session-Id');
        $this->assertNotEmpty($session);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $session, string $tool, array $arguments = []): \Illuminate\Testing\TestResponse
    {
        return $this->rpc([
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], $session)->assertOk();
    }

    #[Test]
    #[TestDox('Подключение агента записывается с именем клиента и сессией')]
    public function initialize_records_the_agent_and_session(): void
    {
        $session = $this->initialize();

        $row = ClientAgentCall::query()->sole();

        $this->assertSame(ClientAgentCall::KIND_MCP_CONNECT, $row->kind);
        $this->assertSame((int) $this->client->id, (int) $row->user_id);
        $this->assertSame((int) $this->token->id, (int) $row->token_id);
        $this->assertSame('claude-code 2.1.0', $row->agent);
        $this->assertSame($session, $row->session_id);
        $this->assertNull($row->tool);
        $this->assertNull($row->operation);
    }

    #[Test]
    #[TestDox('Вызов инструмента — строка с инструментом, операцией, исходом и агентом сессии')]
    public function tool_calls_are_recorded_with_operation_and_outcome(): void
    {
        $session = $this->initialize();

        $this->callTool($session, 'client-catalog');
        $this->callTool($session, 'client-call', ['operation' => 'orders.get', 'arguments' => ['order' => '999999']]);
        $this->callTool($session, 'client-call', ['operation' => 'nope.nothing']);

        $rows = ClientAgentCall::query()->where('kind', ClientAgentCall::KIND_MCP_TOOL)->orderBy('id')->get();

        $this->assertCount(3, $rows);

        $catalog = $rows[0];
        $this->assertSame('client-catalog', $catalog->tool);
        $this->assertTrue($catalog->ok);
        $this->assertNull($catalog->operation);
        // Агент не представляется в каждом вызове — имя подтягивается по сессии.
        $this->assertSame('claude-code 2.1.0', $catalog->agent);
        $this->assertSame($session, $catalog->session_id);
        $this->assertSame((int) $this->token->id, (int) $catalog->token_id);

        $notFound = $rows[1];
        $this->assertSame('client-call', $notFound->tool);
        $this->assertSame('orders.get', $notFound->operation);
        $this->assertFalse($notFound->ok);
        $this->assertSame('not_found', $notFound->error_code);
        $this->assertFalse($notFound->mutating);

        $unknown = $rows[2];
        $this->assertFalse($unknown->ok);
        $this->assertSame('unknown_operation', $unknown->error_code);
        $this->assertNull($unknown->operation);
    }

    #[Test]
    #[TestDox('Пишущая операция помечается как запись и с юрлицом')]
    public function mutating_operations_carry_company_and_flag(): void
    {
        $session = $this->initialize();

        // Заказ без ключа идемпотентности отклоняется до обработчика — но операция,
        // юрлицо и признак записи в журнале уже есть: попытка заказа тоже сигнал.
        $this->callTool($session, 'client-call', [
            'operation' => 'orders.create',
            'arguments' => ['products' => [['product_id' => 1, 'quantity' => 1]]],
        ]);

        $row = ClientAgentCall::query()->where('kind', ClientAgentCall::KIND_MCP_TOOL)->sole();

        $this->assertSame('orders.create', $row->operation);
        $this->assertTrue($row->mutating);
        $this->assertFalse($row->ok);
        $this->assertSame('idempotency_key_required', $row->error_code);
        $this->assertNotNull($row->company_id);
    }

    #[Test]
    #[TestDox('REST v1 пишет в тот же журнал: операция из маршрута, исход из HTTP-кода')]
    public function rest_requests_are_recorded_too(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token->token];

        $this->getJson('/api/client/v1/me', $headers)->assertOk();
        $this->getJson('/api/client/v1/orders/999999', $headers)->assertNotFound();

        $rows = ClientAgentCall::query()->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(ClientAgentCall::KIND_REST, $rows[0]->kind);
        $this->assertSame('me', $rows[0]->operation);
        $this->assertTrue($rows[0]->ok);
        $this->assertNull($rows[0]->tool);
        $this->assertNull($rows[0]->agent);

        $this->assertSame('orders.get', $rows[1]->operation);
        $this->assertFalse($rows[1]->ok);
        $this->assertSame('not_found', $rows[1]->error_code);
        $this->assertSame((int) $this->token->id, (int) $rows[1]->token_id);
    }

    #[Test]
    #[TestDox('Без токена журнал молчит: неавторизованный запрос строки не оставляет')]
    public function unauthorized_requests_leave_no_trace(): void
    {
        $this->getJson('/api/client/v1/me')->assertStatus(401);
        $this->postJson(self::MCP, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], [
            'Accept' => 'application/json, text/event-stream',
        ])->assertStatus(401);

        $this->assertSame(0, ClientAgentCall::query()->count());
    }

    #[Test]
    #[TestDox('Сбой журнала не ломает вызов клиента')]
    public function a_broken_journal_does_not_break_the_call(): void
    {
        Schema::drop('client_agent_calls');

        $response = ClientServer::actingAs($this->client)->tool(ClientCatalog::class);

        $response->assertOk();
        $response->assertSee('operations');
    }

    #[Test]
    #[TestDox('Тестовый транспорт laravel/mcp тоже проходит через журнал')]
    public function the_testing_transport_is_recorded(): void
    {
        ClientServer::actingAs($this->client)->tool(ClientCatalog::class)->assertOk();

        $row = ClientAgentCall::query()->sole();

        $this->assertSame('client-catalog', $row->tool);
        $this->assertNull($row->session_id);
        $this->assertNull($row->token_id);
    }
}
