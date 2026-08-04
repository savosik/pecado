<?php

namespace App\Http\Middleware;

use App\Models\CrmAgentToken;
use App\Support\Crm\CrmSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аутентификация агентского гейта CRM: `/api/crm/*` и `/mcp/crm`.
 *
 * Токен не открывает «доступ к API» — он превращается в конкретного сотрудника.
 * После `Auth::setUser()` работают уже существующие permission-middleware,
 * политики и скоуп `User::visibleInCrm()`, то есть агент физически не может
 * больше своего менеджера. Второй копии правил доступа не появляется, а значит,
 * ей и не с чем разойтись.
 *
 * Заодно поднимается флаг источника: всё, что агент создаст в этом запросе,
 * будет помечено `source = agent`.
 */
class AuthenticateCrmAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $this->unauthorized();
        }

        $token = CrmAgentToken::query()
            ->where('token', $bearer)
            ->where('is_active', true)
            ->with('user')
            ->first();

        // Одна и та же формулировка на «нет токена», «токен отозван» и «владельца
        // больше нет»: не подсказываем, существовал ли токен вообще.
        if (! $token || $token->user === null) {
            return $this->unauthorized();
        }

        // Уволенный сотрудник теряет доступ вместе с ролями — отдельно отзывать
        // его токен не нужно, но и работать через него уже нельзя.
        if (! $token->user->hasCrmAccess()) {
            return $this->unauthorized();
        }

        $token->touchLastUsed();

        Auth::setUser($token->user);
        CrmSource::agent($token->name);

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
