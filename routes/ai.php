<?php

use App\Http\Middleware\AuthenticateAnalyticsMcp;
use App\Mcp\Servers\AnalyticsServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP-серверы
|--------------------------------------------------------------------------
*/

/*
 * Локальный (stdio) — для запуска рядом с приложением (отладка, cron-отчёты
 * на самом сервере). Аутентификация не нужна: доступ уже ограничен тем, кто
 * может выполнить команду в контейнере.
 *   docker exec -i pecado-app php artisan mcp:start analytics
 */
Mcp::local('analytics', AnalyticsServer::class);

/*
 * Веб (Streamable HTTP) — для ИИ-агентов менеджеров с их машин.
 *
 * Открывается наружу через тот же nginx/443, что и сайт, поэтому TLS уже есть.
 * Единственный барьер — AuthenticateAnalyticsMcp (Bearer-токен на менеджера).
 * throttle ограничивает перебор токенов и защищает БД от шквала запросов агента.
 *
 * Доступ только на чтение и без секретных колонок обеспечивается ниже по стеку
 * (bi_agent + вьюхи), поэтому даже с валидным токеном писать в БД нельзя.
 *
 * URL для менеджеров: https://pecado.ru/mcp/analytics
 */
Mcp::web('/mcp/analytics', AnalyticsServer::class)
    ->middleware([AuthenticateAnalyticsMcp::class, 'throttle:60,1']);
