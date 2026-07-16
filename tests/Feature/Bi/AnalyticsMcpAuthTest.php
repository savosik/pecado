<?php

namespace Tests\Feature\Bi;

use App\Models\AnalyticsToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Аутентификация веб-входа аналитического MCP.
 *
 * Проверяется единственный барьер между интернетом и агентом с доступом к ПДн:
 * что без валидного активного токена запрос не проходит. Сами SQL-инструменты
 * здесь не гоняются — на SQLite (phpunit.xml) нет ни bi_agent, ни вьюх analytics;
 * их защиту покрывает ReadOnlySqlRunnerTest.
 */
class AnalyticsMcpAuthTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp/analytics';

    private const INIT = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ],
    ];

    /**
     * @param  array<string, string>  $headers
     */
    private function callMcp(array $headers = [])
    {
        return $this->postJson(self::ENDPOINT, self::INIT, array_merge([
            'Accept' => 'application/json, text/event-stream',
        ], $headers));
    }

    #[Test]
    public function it_rejects_request_without_token(): void
    {
        // WWW-Authenticate на 401 ставит штатный middleware laravel/mcp — по
        // спецификации, с realm и error; проверяем, что он вообще есть.
        $this->callMcp()
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');
    }

    #[Test]
    public function it_rejects_unknown_token(): void
    {
        $this->callMcp(['Authorization' => 'Bearer definitely-not-a-real-token'])
            ->assertStatus(401);
    }

    #[Test]
    public function it_rejects_revoked_token(): void
    {
        $token = AnalyticsToken::issue('Отозванный менеджер');
        $token->forceFill(['is_active' => false])->save();

        $this->callMcp(['Authorization' => 'Bearer '.$token->token])
            ->assertStatus(401);
    }

    #[Test]
    public function it_accepts_valid_token(): void
    {
        $token = AnalyticsToken::issue('Валидный менеджер');

        // initialize не трогает БД аналитики — значит проходит на SQLite и
        // доказывает, что барьер пропустил валидный токен.
        $this->callMcp(['Authorization' => 'Bearer '.$token->token])
            ->assertOk();
    }

    #[Test]
    public function it_records_last_used_at_on_success(): void
    {
        $token = AnalyticsToken::issue('Активный менеджер');
        $this->assertNull($token->last_used_at);

        $this->callMcp(['Authorization' => 'Bearer '.$token->token])->assertOk();

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    #[Test]
    public function revoked_token_keeps_the_row_for_audit(): void
    {
        // Отзыв — это флаг, а не удаление: кто и когда имел доступ, должно
        // оставаться видимым после закрытия доступа.
        $token = AnalyticsToken::issue('Бывший менеджер');
        $token->forceFill(['is_active' => false])->save();

        $this->assertDatabaseHas('analytics_tokens', [
            'id' => $token->id,
            'is_active' => false,
        ]);
    }
}
