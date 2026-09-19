<?php

namespace Tests\Feature\Api\Client;

use App\Models\ClientAgentCall;
use App\Models\User;
use App\Services\Client\Api\ClientApiDocument;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiOpenApiTest extends ClientApiTestCase
{
    #[Test]
    #[TestDox('OpenAPI-документ опубликован и совпадает с реестром операций в обе стороны')]
    public function the_openapi_document_is_published(): void
    {
        $spec = $this->getJson('/docs/client-api.json')->assertOk()->json();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('Pecado Client API', $spec['info']['title']);
        $this->assertArrayHasKey('/api/client/v1/me', $spec['paths']);
        $this->assertSame('me', $spec['paths']['/api/client/v1/me']['get']['operationId']);

        $registry = app(OperationRegistry::class);
        $expected = [];

        foreach ($registry->callable() as $operation) {
            $method = strtolower($operation->method);
            $entry = $spec['paths'][$operation->path()][$method] ?? null;

            $this->assertNotNull($entry, "Операция «{$operation->id}» отсутствует в документе.");
            $this->assertSame($operation->id, $entry['operationId']);
            $this->assertArrayHasKey('200', $entry['responses']);
            $this->assertArrayHasKey('422', $entry['responses']);

            $expected[$operation->path()][$method] = true;
        }

        foreach ($spec['paths'] as $path => $methods) {
            if ($path === '/api/client/v1/me') {
                continue;
            }

            foreach (array_keys($methods) as $method) {
                $this->assertTrue(
                    isset($expected[$path][$method]),
                    "В документе есть {$method} {$path}, а в реестре такой операции нет."
                );
            }
        }

        $this->get('/docs/client-api')->assertOk();
    }

    #[Test]
    #[TestDox('Идемпотентные операции ссылаются на общий заголовок Idempotency-Key, юрлицо описано у companyScoped')]
    public function idempotent_operations_document_the_header(): void
    {
        $spec = $this->getJson('/docs/client-api.json')->assertOk()->json();

        $header = $spec['components']['parameters'][ClientApiDocument::IDEMPOTENCY_PARAMETER];
        $this->assertSame('Idempotency-Key', $header['name']);
        $this->assertSame('header', $header['in']);
        $this->assertSame('string', $header['schema']['type']);

        $company = $spec['components']['parameters'][ClientApiDocument::COMPANY_PARAMETER];
        $this->assertSame('company_id', $company['name']);
        $this->assertSame('integer', $company['schema']['type']);

        foreach (['Envelope', 'Error', 'CursorMeta'] as $schema) {
            $this->assertArrayHasKey($schema, $spec['components']['schemas']);
        }

        $ref = '#/components/parameters/'.ClientApiDocument::IDEMPOTENCY_PARAMETER;

        foreach (app(OperationRegistry::class)->callable() as $operation) {
            $entry = $spec['paths'][$operation->path()][strtolower($operation->method)];
            $refs = array_column($entry['parameters'] ?? [], '$ref');

            if ($operation->idempotent) {
                $this->assertContains($ref, $refs, "Операция «{$operation->id}» идемпотентна, но заголовок не описан.");
                $this->assertStringContainsString(
                    $operation->idempotencyRequired ? 'Обязателен заголовок Idempotency-Key.' : 'Принимает Idempotency-Key.',
                    $entry['description'],
                );
            } else {
                $this->assertNotContains($ref, $refs, "Операция «{$operation->id}» не идемпотентна, а заголовок описан.");
            }

            if ($operation->companyScoped && $operation->param('company_id') === null) {
                $this->assertTrue(
                    $this->documentsCompany($operation, $entry),
                    "Операция «{$operation->id}» привязана к юрлицу, но company_id не описан."
                );
            }
        }
    }

    #[Test]
    #[TestDox('/me указывает адрес OpenAPI-документа')]
    public function me_points_to_docs(): void
    {
        $this->api('GET', '/me')
            ->assertOk()
            ->assertJsonPath('data.docs.openapi', url('/docs/client-api.json'))
            ->assertJsonPath('data.docs.ui', url('/docs/client-api'));
    }

    #[Test]
    #[TestDox('Экран токенов показывает адрес API v1 и ссылки на документацию')]
    public function tokens_screen_shows_v1_url(): void
    {
        $this->actingAs($this->client)
            ->get('/cabinet/api-tokens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/ApiTokens/Index')
                ->where('tokens.0.v1_base_url', url('/api/client/v1'))
                ->where('tokens.0.base_url', url('/api/client-api/'.$this->token->token))
                ->where('docs.ui', url('/docs/client-api'))
                ->where('docs.openapi', url('/docs/client-api.json'))
                ->has('docs.mcp'));
    }

    #[Test]
    #[TestDox('Страница документации грузит Stoplight со своего домена: CSP прода не пускает unpkg.com')]
    public function docs_page_uses_self_hosted_assets(): void
    {
        $this->get('/docs/client-api')
            ->assertOk()
            ->assertSee(asset('vendor/stoplight-elements/8.4.2/web-components.min.js'), false)
            ->assertDontSee('unpkg.com', false);

        $this->assertFileExists(public_path('vendor/stoplight-elements/8.4.2/web-components.min.js'));
        $this->assertFileExists(public_path('vendor/stoplight-elements/8.4.2/styles.min.css'));
    }

    #[Test]
    #[TestDox('Раздел «ИИ-агенты (MCP)» отдаёт адрес сервера и активный ключ клиента')]
    public function mcp_screen_shows_server_and_key(): void
    {
        $this->actingAs($this->client)
            ->get('/cabinet/mcp')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/ApiTokens/Mcp')
                ->where('apiKey', $this->token->token)
                ->where('docs.mcp', url('/mcp/client'))
                ->where('docs.ui', url('/docs/client-api'))
                ->where('connection.has_inactive_keys', false)
                ->where('connection.last_connected_at', null)
                ->where('connection.agent', null));
    }

    #[Test]
    #[TestDox('Без активного ключа раздел MCP не отдаёт образец, а сообщает, что ключи отключены')]
    public function mcp_screen_without_active_key_has_no_sample(): void
    {
        $this->token->update(['is_active' => false]);

        $this->actingAs($this->client)
            ->get('/cabinet/mcp')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/ApiTokens/Mcp')
                ->where('apiKey', null)
                ->where('connection.has_inactive_keys', true));

        $this->token->delete();

        $this->actingAs($this->client)
            ->get('/cabinet/mcp')
            ->assertInertia(fn ($page) => $page
                ->where('apiKey', null)
                ->where('connection.has_inactive_keys', false));
    }

    #[Test]
    #[TestDox('Раздел MCP показывает последнее подключение агента по журналу вызовов')]
    public function mcp_screen_shows_the_last_agent_connection(): void
    {
        ClientAgentCall::create([
            'kind' => ClientAgentCall::KIND_MCP_CONNECT, 'user_id' => $this->client->id,
            'agent' => 'cursor 1.0', 'ok' => true, 'created_at' => now()->subDays(3),
        ]);
        ClientAgentCall::create([
            'kind' => ClientAgentCall::KIND_MCP_CONNECT, 'user_id' => $this->client->id,
            'agent' => 'claude-code 2.1.0', 'ok' => true, 'created_at' => now()->subHour(),
        ]);
        ClientAgentCall::create([
            'kind' => ClientAgentCall::KIND_MCP_CONNECT, 'user_id' => User::factory()->create()->id,
            'agent' => 'чужой', 'ok' => true, 'created_at' => now(),
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/mcp')
            ->assertInertia(fn ($page) => $page
                ->where('connection.agent', 'claude-code 2.1.0')
                ->where('connection.last_connected_at', fn ($v) => now()->subHour()->diffInSeconds(\Carbon\Carbon::parse($v), true) < 5));
    }

    #[Test]
    #[TestDox('Раздел «Legacy API» показывает адреса с ключом только своих ключей')]
    public function legacy_screen_shows_own_legacy_urls(): void
    {
        $this->actingAs($this->client)
            ->get('/cabinet/api-legacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/ApiTokens/Legacy')
                ->has('tokens', 1)
                ->where('tokens.0.base_url', url('/api/client-api/'.$this->token->token))
                ->missing('tokens.0.token'));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function documentsCompany(Operation $operation, array $entry): bool
    {
        if (in_array($operation->method, ['GET', 'DELETE'], true)) {
            return in_array(
                '#/components/parameters/'.ClientApiDocument::COMPANY_PARAMETER,
                array_column($entry['parameters'] ?? [], '$ref'),
                true,
            );
        }

        return isset($entry['requestBody']['content']['application/json']['schema']['properties']['company_id']);
    }
}
