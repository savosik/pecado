<?php

namespace Tests\Feature\Assistant;

use App\Models\ApiToken;
use App\Models\ClientAgentCall;
use App\Services\Assistant\AssistantTokenIssuer;
use App\Services\Assistant\ThreadService;
use App\Services\Client\Api\Usage\UsageRecorder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Токен вида assistant: живёт TTL, не показывается клиенту, помечает канал в журнале.
 */
class AssistantTokenTest extends AssistantTestCase
{
    #[Test]
    public function истёкший_токен_не_проходит_аутентификацию(): void
    {
        $token = $this->assistantToken();
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/client/v1/me', ['Authorization' => 'Bearer '.$token->token])
            ->assertStatus(401);

        $token->forceFill(['expires_at' => now()->addMinute()])->save();

        $this->getJson('/api/client/v1/me', ['Authorization' => 'Bearer '.$token->token])
            ->assertOk();
    }

    #[Test]
    public function токен_помощника_не_показывается_в_кабинете(): void
    {
        $personal = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Мой ключ', 'is_active' => true]);
        $this->assistantToken();

        $this->actingAs($this->client)
            ->get('/cabinet/api-tokens')
            ->assertInertia(fn ($page) => $page
                ->has('tokens', 1)
                ->where('tokens.0.id', $personal->id));
    }

    #[Test]
    public function выпуск_на_тред_продление_и_отзыв(): void
    {
        config()->set('assistant.token_ttl_minutes', 30);
        $issuer = app(AssistantTokenIssuer::class);
        $thread = app(ThreadService::class)->open($this->client);

        $token = $issuer->forThread($thread);
        $this->assertSame(ApiToken::KIND_ASSISTANT, $token->kind);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertSame($token->id, $thread->refresh()->token_id);

        $this->travel(20)->minutes();
        $again = $issuer->forThread($thread->refresh());
        $this->assertSame($token->id, $again->id, 'действующий токен переиспользуется');
        $this->assertTrue($again->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)), 'срок продлён');

        app(ThreadService::class)->close($thread);
        $this->assertFalse($token->refresh()->is_active);

        $this->travelBack();
    }

    #[Test]
    public function истёкшие_токены_деактивируются_а_личные_не_трогаются(): void
    {
        $personal = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Мой ключ', 'is_active' => true]);
        $expired = $this->assistantToken();
        $expired->forceFill(['expires_at' => now()->subHour()])->save();
        $alive = $this->assistantToken();

        $this->assertSame(1, app(AssistantTokenIssuer::class)->deactivateExpired());
        $this->assertFalse($expired->refresh()->is_active);
        $this->assertTrue($alive->refresh()->is_active);
        $this->assertTrue($personal->refresh()->is_active);
    }

    #[Test]
    public function вызов_mcp_с_токеном_помощника_попадает_в_журнал_каналом_web_assistant(): void
    {
        $token = $this->assistantToken();

        $this->postJson('/mcp/client', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'claude', 'version' => '1.0']],
        ], ['Authorization' => 'Bearer '.$token->token, 'Accept' => 'application/json, text/event-stream'])
            ->assertOk();

        $call = ClientAgentCall::query()->where('kind', ClientAgentCall::KIND_MCP_CONNECT)->first();
        $this->assertNotNull($call);
        $this->assertSame(UsageRecorder::AGENT_WEB_ASSISTANT, $call->agent);
        $this->assertSame($token->id, $call->token_id);
    }
}
