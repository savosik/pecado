<?php

namespace App\Http\Middleware;

use App\Services\Delivery\ApiShipSettings;
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
 * allowlist IP. Без секрета эндпоинт не работает вообще: пустой секрет означал бы
 * публичный приём чужих статусов.
 *
 * Флаг и секрет читаются через `ApiShipSettings`, а не из конфига напрямую: их
 * ведёт начальник склада на `/wms/delivery-settings`, и значение из базы должно
 * перекрывать `.env`. Иначе форма пишет секрет в базу, а гейт продолжает сверять
 * его с пустым `.env` и отвечает 503 на каждый вебхук.
 */
class ApiShipWebhookEnabled
{
    public function __construct(private readonly ApiShipSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->bool('webhook_enabled')) {
            return response()->json(['message' => 'Вебхук ApiShip выключен'], 503);
        }

        $expected = $this->settings->string('webhook_secret');

        if ($expected === '') {
            Log::warning('ApiShip: вебхук включён, но секрет не задан — запрос отклонён');

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
