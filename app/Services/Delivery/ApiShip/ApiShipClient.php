<?php

namespace App\Services\Delivery\ApiShip;

use App\Models\Delivery\ApiShipRequest;
use App\Services\Delivery\ApiShipSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Транспорт ApiShip. Ничего не знает про отправки — только шлёт запросы.
 *
 * Как и SupplierOrderApi, возвращает нормализованный массив-результат вместо
 * исключений: вызов перевозчика падает регулярно (таймаут, 502 балансировщика,
 * ошибка валидации от СД), и каждый такой случай — штатная ветка бизнес-логики,
 * а не аварийная ситуация.
 *
 * Авторизация: POST /login отдаёт бессрочный токен, дальше он идёт в заголовке
 * Authorization без схемы (не Bearer). Токен лежит в кэше; 401 сбрасывает кэш
 * и запрос повторяется один раз.
 *
 * @see https://docs.apiship.ru/docs/api/
 */
class ApiShipClient
{
    /** Ключ кэша токена. */
    private const TOKEN_CACHE_KEY = 'apiship:token';

    public function __construct(
        private readonly ApiShipRequestLogger $logger,
        private readonly ApiShipSettings $settings,
    ) {}

    public function enabled(): bool
    {
        if (! $this->settings->bool('enabled')) {
            return false;
        }

        // Либо готовый токен из кабинета, либо пара логин/пароль для POST /login.
        return $this->settings->string('token') !== ''
            || ($this->settings->string('login') !== '' && $this->settings->string('password') !== '');
    }

    // ─────────────────────────── Публичные методы API ───────────────────────────

    /**
     * Расчёт стоимости доставки. Вес в граммах, габариты в сантиметрах.
     *
     * @param  array<string, mixed>  $payload
     */
    public function calculate(array $payload, ?int $triggeredBy = null): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_CALCULATOR, 'POST', '/calculator', $payload, triggeredBy: $triggeredBy);
    }

    /**
     * Создание заявки. Асинхронное: в ответе только orderId, трек приезжает позже.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createOrder(array $payload, ?int $shipmentId = null, ?int $triggeredBy = null): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_CREATE_ORDER, 'POST', '/orders', $payload, $shipmentId, $triggeredBy);
    }

    public function getOrder(string $orderId, ?int $shipmentId = null): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_GET_ORDER, 'GET', '/orders/'.$orderId, null, $shipmentId);
    }

    /**
     * Отмена заявки. У ApiShip это именно GET — не опечатка.
     */
    public function cancelOrder(string $orderId, ?int $shipmentId = null, ?int $triggeredBy = null): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_CANCEL_ORDER, 'GET', '/orders/'.$orderId.'/cancel', null, $shipmentId, $triggeredBy);
    }

    /**
     * Все смены статусов за интервал — один запрос вместо опроса каждой заявки.
     */
    public function getStatusesInterval(\DateTimeInterface $from, \DateTimeInterface $to): ApiShipResult
    {
        return $this->call(
            ApiShipRequest::OPERATION_STATUSES_INTERVAL,
            'GET',
            '/orders/statuses/interval',
            null,
            query: [
                'dateStart' => $from->format('Y-m-d H:i:s'),
                'dateEnd' => $to->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Список пунктов выдачи.
     *
     * @param  array<string, mixed>  $query
     */
    public function getPoints(array $query): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_POINTS, 'GET', '/lists/points', null, query: $query);
    }

    /**
     * Ярлыки (этикетки) для заявок. В ответе — ссылка на PDF в хранилище ApiShip.
     *
     * @param  list<string>  $orderIds
     */
    public function getLabels(array $orderIds, ?int $shipmentId = null, ?int $triggeredBy = null): ApiShipResult
    {
        return $this->call(
            ApiShipRequest::OPERATION_DOCUMENT,
            'POST',
            '/orders/labels',
            ['orderIds' => array_map('intval', $orderIds), 'format' => 'pdf'],
            $shipmentId,
            $triggeredBy,
        );
    }

    /**
     * Акт приёма-передачи по заявкам.
     *
     * @param  list<string>  $orderIds
     */
    public function getWaybills(array $orderIds, ?int $shipmentId = null, ?int $triggeredBy = null): ApiShipResult
    {
        return $this->call(
            ApiShipRequest::OPERATION_DOCUMENT,
            'POST',
            '/orders/waybills',
            ['orderIds' => array_map('intval', $orderIds), 'format' => 'pdf'],
            $shipmentId,
            $triggeredBy,
        );
    }

    /**
     * Вызов курьера за грузом.
     *
     * @param  array<string, mixed>  $payload
     */
    public function callCourier(array $payload, ?int $shipmentId = null, ?int $triggeredBy = null): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_COURIER, 'POST', '/courierCall', $payload, $shipmentId, $triggeredBy);
    }

    // ─────────────────────────── Вебхуки ───────────────────────────

    /**
     * Подписка на событие. Поле называется `type`, а не `eventType`: последнее
     * ApiShip молча отвергает («Атрибут `type` обязателен»), хотя в теле события
     * тип приезжает обратно именно как `eventType`.
     */
    public function subscribeWebhook(string $url, string $eventType = 'ORDER_STATUS'): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_WEBHOOK, 'POST', '/webhooks', [
            'url' => $url,
            'type' => $eventType,
        ]);
    }

    public function listWebhooks(): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_WEBHOOK, 'GET', '/webhooks');
    }

    public function deleteWebhook(string $uuid): ApiShipResult
    {
        return $this->call(ApiShipRequest::OPERATION_WEBHOOK, 'DELETE', '/webhooks/'.$uuid);
    }

    // ─────────────────────────── Внутренности ───────────────────────────

    /**
     * Выполнить запрос: получить токен, сходить в API, записать вызов в журнал.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $query
     */
    private function call(
        string $operation,
        string $method,
        string $path,
        ?array $payload = null,
        ?int $shipmentId = null,
        ?int $triggeredBy = null,
        array $query = [],
    ): ApiShipResult {
        if (! $this->enabled()) {
            return ApiShipResult::disabled();
        }

        $token = $this->token();

        if ($token === null) {
            return ApiShipResult::failure('Не удалось авторизоваться в ApiShip: проверьте APISHIP_LOGIN и APISHIP_PASSWORD');
        }

        $result = $this->send($token, $method, $path, $payload, $query);

        // Токен в кэше протух (или отозван в кабинете ApiShip) — логинимся заново
        // и повторяем ровно один раз, чтобы не зациклиться на постоянном 401.
        // При токене из конфига перелогин бессмысленен: он вернёт то же значение.
        if ($result->httpStatus === 401 && $this->settings->string('token') === '') {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $token = $this->token();

            if ($token !== null) {
                $result = $this->send($token, $method, $path, $payload, $query);
            }
        }

        $this->logger->log(
            $operation,
            $method,
            $path,
            $payload,
            $result->toArray(),
            $shipmentId,
            $triggeredBy,
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $query
     */
    private function send(string $token, string $method, string $path, ?array $payload, array $query): ApiShipResult
    {
        $request = $this->http()->withHeaders(['Authorization' => $token]);

        // Повтор только для чтения: POST /orders неидемпотентен, и автоматический
        // ретрай после таймаута завёл бы у перевозчика вторую заявку на тот же груз.
        if ($method === 'GET') {
            $request = $request->retry(2, 500, throw: false);
        }

        $url = $this->baseUrl().$path;

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $startedAt = microtime(true);

        try {
            $response = match ($method) {
                'GET' => $request->get($url),
                'DELETE' => $request->delete($url),
                default => $request->post($url, $payload ?? []),
            };
        } catch (\Throwable $e) {
            return ApiShipResult::transportError($e->getMessage(), $this->elapsed($startedAt));
        }

        return ApiShipResult::fromResponse($response, $this->elapsed($startedAt));
    }

    /**
     * Токен авторизации. Кэшируется — логиниться перед каждым запросом незачем.
     */
    private function token(): ?string
    {
        // Токен задан явно (в форме настроек или в .env) — ходить за ним не надо.
        $configured = $this->settings->string('token');

        if ($configured !== '') {
            return $configured;
        }

        $ttl = (int) config('services.apiship.token_ttl', 86400);

        $token = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $startedAt = microtime(true);
        $credentials = [
            'login' => $this->settings->string('login'),
            'password' => $this->settings->string('password'),
        ];

        try {
            $response = $this->http()->post($this->baseUrl().'/login', $credentials);
            $result = ApiShipResult::fromResponse($response, $this->elapsed($startedAt));
        } catch (\Throwable $e) {
            $result = ApiShipResult::transportError($e->getMessage(), $this->elapsed($startedAt));
        }

        $this->logger->log(ApiShipRequest::OPERATION_LOGIN, 'POST', '/login', $credentials, $result->toArray());

        $token = $result->json['token'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(15)
            ->timeout($this->settings->int('timeout', 30));
    }

    private function baseUrl(): string
    {
        return rtrim($this->settings->string('base_url'), '/');
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
