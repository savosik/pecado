<?php

namespace Tests\Feature\Assistant;

use App\Models\ApiToken;
use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\Gateway\AssistantGateway;
use App\Support\Client\ClientApiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Assistant\FakeGateway;
use Tests\TestCase;

/**
 * Общая обвязка тестов помощника: клиент с юрлицом, включённый помощник,
 * фейковый шлюз вместо Anthropic.
 */
abstract class AssistantTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $client;

    protected Company $company;

    protected FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('assistant.enabled', true);
        config()->set('assistant.api_key', 'sk-ant-test');
        config()->set('assistant.model', 'claude-opus-5');
        config()->set('assistant.attachments.disk', 'local');
        config()->set('assistant.queue_connection', 'sync');
        Cache::forget(AssistantAvailability::KEY);

        $this->gateway = new FakeGateway;
        $this->app->instance(AssistantGateway::class, $this->gateway);

        $this->client = User::factory()->create(['name' => 'Клиент', 'erp_id' => (string) \Illuminate\Support\Str::uuid()]);
        $this->company = Company::factory()->create([
            'user_id' => $this->client->id,
            'name' => 'ООО Ромашка',
            'tax_id' => '7707083893',
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        ClientApiSource::reset();
        parent::tearDown();
    }

    protected function assistantToken(?User $user = null): ApiToken
    {
        return ApiToken::create([
            'user_id' => ($user ?? $this->client)->id,
            'name' => 'Помощник',
            'kind' => ApiToken::KIND_ASSISTANT,
            'is_active' => true,
            'expires_at' => now()->addHour(),
        ]);
    }
}
