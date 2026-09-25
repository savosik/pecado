<?php

namespace Tests\Feature\Api\Client;

use App\Models\ApiToken;
use App\Models\Company;
use App\Models\User;
use App\Support\Client\ClientApiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Общая обвязка тестов клиентского API v1: клиент, его юрлицо и токен.
 */
abstract class ClientApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $client;

    protected Company $company;

    protected ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['name' => 'Клиент', 'erp_id' => (string) \Illuminate\Support\Str::uuid()]);
        $this->company = Company::factory()->create([
            'user_id' => $this->client->id,
            'tax_id' => '7707083893',
            'is_default' => true,
        ]);
        $this->token = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Агент клиента', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        ClientApiSource::reset();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null): TestResponse
    {
        return $this->json($method, '/api/client/v1'.$uri, $data, array_merge(
            ['Authorization' => 'Bearer '.($token ?? $this->token->token)],
            $headers,
        ));
    }
}
