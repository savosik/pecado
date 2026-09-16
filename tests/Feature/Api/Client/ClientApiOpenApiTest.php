<?php

namespace Tests\Feature\Api\Client;

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
