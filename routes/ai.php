<?php

use App\Http\Middleware\AuthenticateAnalyticsMcp;
use App\Http\Middleware\AuthenticateCrmAgent;
use App\Http\Middleware\AuthenticatePurchasingAgent;
use App\Mcp\Servers\AnalyticsServer;
use App\Mcp\Servers\CrmServer;
use App\Mcp\Servers\PurchasingServer;
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

/*
 * CRM отдела продаж — чтение и запись от имени менеджера.
 *
 * Отдельный сервер и отдельные токены, а не расширение аналитического: там весь
 * смысл в том, что писать в БД невозможно даже с валидным токеном, и добавлять
 * туда запись означало бы разрушить единственную гарантию, ради которой сервер
 * и сделан таким.
 *
 * Локального (stdio) транспорта нет намеренно: пишущий доступ имеет смысл только
 * с машины менеджера, а команда в контейнере — это уже доступ на сервер.
 *
 * URL для менеджеров: https://pecado.ru/mcp/crm
 */
Mcp::web('/mcp/crm', CrmServer::class)
    ->middleware([AuthenticateCrmAgent::class, 'throttle:60,1']);

/*
 * Уценка глазами агента закупщика — осмотр партий по фото, цена, публикация.
 *
 * Отдельный сервер и отдельные токены (purchasing_agent_tokens): токен
 * закупщика не должен открывать CRM, а токен менеджера — цены уценки.
 * Права проверяются обычными permission-ами владельца токена
 * (defects.view / defects.price / defects.publish).
 *
 * URL для агента: https://pecado.ru/mcp/purchasing
 */
Mcp::web('/mcp/purchasing', PurchasingServer::class)
    ->middleware([AuthenticatePurchasingAgent::class, 'throttle:60,1']);
