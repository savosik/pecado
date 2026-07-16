<?php

namespace App\Http\Middleware;

use App\Models\AnalyticsToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аутентификация аналитического MCP-сервера по Bearer-токену менеджера.
 *
 * Это единственный барьер между интернетом и агентом с доступом к ПДн, поэтому:
 *  - токен обязателен (нет заголовка → 401, а не тихий пропуск);
 *  - имя владельца кладётся в request, чтобы попасть в лог каждого SQL —
 *    «кто спросил» должно быть в записи, а не восстанавливаться догадками;
 *  - при отзыве (is_active=false) доступ закрывается сразу, без удаления токена.
 */
class AuthenticateAnalyticsMcp
{
    /** Ключ, под которым имя владельца токена доступно приложению в этом запросе. */
    public const ACTOR_ATTRIBUTE = 'analytics_actor';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('Требуется Bearer-токен.');
        }

        $model = AnalyticsToken::where('token', $token)
            ->where('is_active', true)
            ->first();

        if (! $model) {
            // Одна и та же формулировка на «нет токена» и «токен отозван»:
            // не подсказываем, существовал ли токен вообще.
            return $this->unauthorized('Токен недействителен или отозван.');
        }

        $model->touchLastUsed();
        $request->attributes->set(self::ACTOR_ATTRIBUTE, $model->name);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        // Заголовок WWW-Authenticate не ставим руками: его на 401 добавляет
        // штатный middleware laravel/mcp (AddWwwAuthenticateHeader) в форме,
        // требуемой спецификацией (realm, error) — наш ручной он бы перетёр.
        return response()->json(['error' => $message], 401);
    }
}
