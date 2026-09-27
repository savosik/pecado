<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Client\ClientApiSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аутентификация клиентского API v1 и MCP клиента: `/api/client/v1/*`, `/mcp/client`.
 *
 * Тот же токен, что у legacy `/api/client-api/{token}` (таблица api_tokens), но
 * в заголовке `Authorization: Bearer`, а не в пути: токен в URL оседает в логах
 * nginx и истории агента. Токен превращается в клиента — после `Auth::setUser()`
 * все операции работают его данными и его юрлицами, второй копии правил нет.
 */
class AuthenticateClientApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $this->unauthorized();
        }

        if (self::looksLikePlaceholder($bearer)) {
            return $this->placeholder();
        }

        $token = ApiToken::query()
            ->where('token', $bearer)
            ->where('is_active', true)
            ->with('user')
            ->first();

        // Одна формулировка на «нет токена», «отозван» и «владельца нет»:
        // не подсказываем, существовал ли токен вообще.
        if (! $token || $token->user === null) {
            return $this->unauthorized();
        }

        // Токен чата-помощника живёт TTL и отзывается по закрытии треда;
        // истёкший неотличим от отозванного — та же формулировка.
        if ($token->isExpired()) {
            return $this->unauthorized();
        }

        // Как в legacy: отметка «когда пользовались» не чаще раза в минуту,
        // иначе каждый запрос агента — UPDATE.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->touchLastUsed();
        }

        Auth::setUser($token->user);
        ClientApiSource::token($token);

        return $next($request);
    }

    /**
     * В заголовке остался образец из инструкции («<ВАШ_КЛЮЧ>», «YOUR_KEY»),
     * а не ключ. Настоящий ключ — 64 hex-символа, так что угловые скобки и
     * слова «ключ/key/token» в нём невозможны. Отдельная формулировка нужна,
     * потому что агент передаёт её клиенту словами: «токен отозван» звучит
     * как поломка сайта, а здесь ключ просто ещё не создан.
     */
    public static function looksLikePlaceholder(string $bearer): bool
    {
        return str_contains($bearer, '<')
            || str_contains($bearer, '>')
            || preg_match('/ключ|key|token/iu', $bearer) === 1;
    }

    private function placeholder(): Response
    {
        return response()->json([
            'errors' => [[
                'code' => 'key_placeholder',
                'message' => 'В настройке вместо ключа остался образец. Создайте ключ в кабинете, '
                    .'раздел «ИИ-агенты (MCP)»: '.url('/cabinet/mcp').' — и подставьте его в Authorization.',
            ]],
        ], 401);
    }

    private function unauthorized(): Response
    {
        // WWW-Authenticate для MCP ставит штатный middleware laravel/mcp.
        return response()->json([
            'errors' => [['code' => 'unauthorized', 'message' => 'Токен недействителен или отозван.']],
        ], 401);
    }
}
