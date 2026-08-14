<?php

namespace App\Http\Middleware;

use App\Models\PurchasingAgentToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аутентификация агентского гейта закупок: `/mcp/purchasing`.
 *
 * Токен не открывает «доступ к API» — он превращается в конкретного закупщика.
 * После `Auth::setUser()` инструменты проверяют его обычные права
 * `defects.price` / `defects.publish`, то есть агент физически не может
 * больше своего владельца. Второй копии правил доступа не появляется,
 * а значит, ей и не с чем разойтись.
 */
class AuthenticatePurchasingAgent
{
    /** Имя токена в атрибутах запроса — попадает в аудит операций записи. */
    public const TOKEN_NAME_ATTRIBUTE = 'purchasing_agent_token_name';

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $this->unauthorized();
        }

        $token = PurchasingAgentToken::query()
            ->where('token', $bearer)
            ->where('is_active', true)
            ->with('user')
            ->first();

        // Одна и та же формулировка на «нет токена», «токен отозван» и «владельца
        // больше нет»: не подсказываем, существовал ли токен вообще.
        if (! $token || $token->user === null) {
            return $this->unauthorized();
        }

        // Сотрудник без права на уценку теряет доступ вместе с ролью — отдельно
        // отзывать его токен не нужно, но и работать через него уже нельзя.
        if (! $token->user->can('defects.view')) {
            return $this->unauthorized();
        }

        $token->touchLastUsed();

        Auth::setUser($token->user);
        $request->attributes->set(self::TOKEN_NAME_ATTRIBUTE, $token->name);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        // Заголовок WWW-Authenticate на 401 для MCP-маршрута добавляет штатный
        // middleware laravel/mcp в форме, требуемой спецификацией; свой здесь
        // перетёр бы его.
        return response()->json(['message' => 'Токен недействителен или отозван.'], 401);
    }
}
