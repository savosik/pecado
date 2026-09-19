<?php

namespace App\Services\Client\Api\Usage;

use App\Models\ClientAgentCall;
use App\Models\User;
use App\Support\Client\ClientApiSource;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Запись строк журнала вызовов агентов клиентов (`client_agent_calls`).
 *
 * Журнал — наблюдение, а не часть операции: сбой записи ловится и докладывается
 * через `report()`, но вызов клиента от него не падает. Имя ИИ-клиента приходит
 * один раз в `initialize` и запоминается по сессии MCP, чтобы каждая строка
 * инструмента знала, чей это агент.
 */
final class UsageRecorder
{
    /** Сколько помнить «сессия → агент»: разговор в Claude Code живёт часами, не сутками. */
    private const AGENT_TTL_DAYS = 30;

    public function __construct(private readonly UsageContext $context) {}

    /**
     * Агент представился: запомнить по сессии и отметить подключение.
     *
     * @param  array<string, mixed>|null  $clientInfo
     */
    public function mcpConnected(User $actor, ?string $sessionId, ?array $clientInfo): void
    {
        $this->context->begin();
        $agent = self::agentLabel($clientInfo);

        if ($sessionId !== null && $agent !== null) {
            Cache::put(self::agentKey($sessionId), $agent, now()->addDays(self::AGENT_TTL_DAYS));
        }

        $this->write([
            'kind' => ClientAgentCall::KIND_MCP_CONNECT,
            'user_id' => $actor->getKey(),
            'session_id' => $sessionId,
            'agent' => $agent,
        ]);
    }

    /**
     * Инструмент MCP отработал (или упал).
     */
    public function mcpToolCalled(User $actor, ?string $sessionId, string $tool, bool $ok, ?string $errorCode, int $durationMs): void
    {
        $this->write([
            'kind' => ClientAgentCall::KIND_MCP_TOOL,
            'user_id' => $actor->getKey(),
            'session_id' => $sessionId,
            'agent' => $sessionId !== null ? Cache::get(self::agentKey($sessionId)) : null,
            'tool' => $tool,
            'ok' => $ok,
            'error_code' => $ok ? null : ($errorCode ?? 'error'),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * REST-запрос v1 отработал. `$operation` — идентификатор из имени маршрута
     * (`me` для discovery), даже если до реестра дело не дошло.
     */
    public function restCalled(User $actor, ?string $operation, bool $ok, ?string $errorCode, int $durationMs): void
    {
        $this->write([
            'kind' => ClientAgentCall::KIND_REST,
            'user_id' => $actor->getKey(),
            'operation' => $this->context->operationId() ?? $operation,
            'ok' => $ok,
            'error_code' => $ok ? null : ($errorCode ?? 'error'),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * «claude-code 2.1.0» из clientInfo протокола; без имени — null.
     *
     * @param  array<string, mixed>|null  $clientInfo
     */
    public static function agentLabel(?array $clientInfo): ?string
    {
        $name = trim((string) ($clientInfo['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $version = trim((string) ($clientInfo['version'] ?? ''));

        return mb_substr($version === '' ? $name : "{$name} {$version}", 0, 120);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function write(array $row): void
    {
        try {
            ClientAgentCall::query()->create($row + [
                'token_id' => ClientApiSource::tokenId(),
                'company_id' => $this->context->companyId(),
                'operation' => $this->context->operationId(),
                'mutating' => $this->context->isMutating(),
                'ok' => true,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function agentKey(string $sessionId): string
    {
        return 'client-agent:session:'.$sessionId;
    }
}
