<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\Client\ClientApiController;
use App\Models\User;
use App\Services\Client\Api\Usage\UsageContext;
use App\Services\Client\Api\Usage\UsageRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Журнал вызовов REST v1 (`/api/client/v1/*`): одна строка на запрос.
 *
 * Стоит после аутентификации — без клиента писать нечего. Операция берётся из
 * имени маршрута (реестр даёт маршрутам свои идентификаторы), исход — из HTTP-кода,
 * код отказа — из конверта ошибок ответа. Тот же журнал, что у MCP, чтобы на
 * экране «ИИ-агенты клиентов» оба канала лежали рядом.
 */
class RecordClientApiUsage
{
    public function __construct(
        private readonly UsageContext $context,
        private readonly UsageRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->begin();
        $started = hrtime(true);

        $response = $next($request);

        $actor = $request->user();

        if ($actor instanceof User) {
            $status = $response->getStatusCode();

            $this->recorder->restCalled(
                $actor,
                $this->operationFromRoute($request),
                $status < 400,
                $status < 400 ? null : ($this->context->errorCode() ?? $this->errorCodeFromResponse($response, $status)),
                (int) round((hrtime(true) - $started) / 1_000_000),
            );
        }

        return $response;
    }

    private function operationFromRoute(Request $request): ?string
    {
        $name = (string) ($request->route()?->getName() ?? '');

        if (! Str::startsWith($name, ClientApiController::ROUTE_PREFIX)) {
            return null;
        }

        return mb_substr(Str::after($name, ClientApiController::ROUTE_PREFIX), 0, 64);
    }

    private function errorCodeFromResponse(Response $response, int $status): string
    {
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $code = is_array($data) ? ($data['errors'][0]['code'] ?? null) : null;

            if (is_string($code) && $code !== '') {
                return mb_substr($code, 0, 64);
            }
        }

        return 'http_'.$status;
    }
}
