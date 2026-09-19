<?php

namespace App\Services\Assistant;

use App\Models\ApiToken;
use App\Models\ChatThread;
use App\Models\User;

/**
 * Токены чата-помощника: выпуск на тред, продление, отзыв.
 *
 * Токен проходит транзитом через сервер Anthropic (MCP-коннектор), поэтому
 * это не личный ключ клиента, а отдельный вид `assistant` с TTL из конфига.
 * Права те же — токен один и полный (решение capi-00), — отличается только
 * срок жизни. Клиенту такие токены не показываются.
 */
final class AssistantTokenIssuer
{
    /**
     * Действующий токен треда или новый, если прежнего нет, он отозван или истёк.
     */
    public function forThread(ChatThread $thread): ApiToken
    {
        $token = $thread->token;

        if ($token !== null && $token->is_active && ! $token->isExpired() && $token->isAssistant()) {
            $this->renew($token);

            return $token;
        }

        $fresh = ApiToken::create([
            'user_id' => $thread->user_id,
            'name' => 'Помощник в кабинете (тред #'.$thread->id.')',
            'kind' => ApiToken::KIND_ASSISTANT,
            'is_active' => true,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);

        $thread->forceFill(['token_id' => $fresh->id])->save();

        return $fresh;
    }

    /** Продлить срок на TTL от текущего момента — клиент активен. */
    public function renew(ApiToken $token): void
    {
        $until = now()->addMinutes($this->ttlMinutes());

        if ($token->expires_at === null || $token->expires_at->lt($until->subMinutes(5))) {
            $token->forceFill(['expires_at' => now()->addMinutes($this->ttlMinutes())])->save();
        }
    }

    /** Тред закрыт — токен больше не нужен. */
    public function revokeForThread(ChatThread $thread): void
    {
        $token = $thread->token;

        if ($token !== null && $token->isAssistant()) {
            $token->forceFill(['is_active' => false, 'expires_at' => now()])->save();
        }
    }

    /**
     * Истёкшие токены помощника, которые никто не отозвал (обрыв воркера):
     * деактивировать, чтобы не копились активными.
     */
    public function deactivateExpired(): int
    {
        return ApiToken::query()
            ->where('kind', ApiToken::KIND_ASSISTANT)
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['is_active' => false]);
    }

    /** Все действующие токены помощника клиента — отозвать (например, при смене пароля). */
    public function revokeAllFor(User $user): int
    {
        return ApiToken::query()
            ->where('user_id', $user->getKey())
            ->where('kind', ApiToken::KIND_ASSISTANT)
            ->where('is_active', true)
            ->update(['is_active' => false, 'expires_at' => now()]);
    }

    private function ttlMinutes(): int
    {
        return max(5, (int) config('assistant.token_ttl_minutes', 60));
    }
}
