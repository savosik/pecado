<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Гейт вебхука ApiShip.
 *
 * Подписи запроса ApiShip не предоставляет, поэтому защита складывается из трёх
 * независимых слоёв: мастер-флаг, секретный сегмент URL (сравнивается через
 * hash_equals — обычное `===` даёт таймингову́ю утечку) и необязательный
 * allowlist IP. Без секрета в конфиге эндпоинт не работает вообще: пустой
 * секрет означал бы публичный приём чужих статусов.
 */
class ApiShipWebhookEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.apiship.webhook.enabled')) {
            return response()->json(['message' => 'Вебхук ApiShip выключен'], 503);
        }

        $expected = (string) config('services.apiship.webhook.secret');

        if ($expected === '') {
            Log::warning('ApiShip: вебхук включён, но APISHIP_WEBHOOK_SECRET не задан — запрос отклонён');

            return response()->json(['message' => 'Вебхук ApiShip не настроен'], 503);
        }

        $provided = (string) $request->route('secret');

        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        /** @var list<string> $allowedIps */
        $allowedIps = (array) config('services.apiship.webhook.allowed_ips', []);

        if ($allowedIps !== [] && ! in_array((string) $request->ip(), $allowedIps, true)) {
            Log::warning('ApiShip: вебхук с неразрешённого IP', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        return $next($request);
    }
}
