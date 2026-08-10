<?php

namespace App\Services\Delivery\ApiShip;

use App\Models\Delivery\ApiShipRequest;
use Illuminate\Support\Facades\Log;

/**
 * Пишет каждый вызов ApiShip в журнал `apiship_requests`.
 *
 * Логирование не должно ронять доставку: если запись в БД не удалась (а она идёт
 * в том же запросе, что и обращение к перевозчику), падаем в обычный лог и едем дальше.
 */
class ApiShipRequestLogger
{
    /**
     * Ключи, которые нельзя сохранять в открытом виде.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = ['password', 'token', 'apiKey', 'authorization'];

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array{ok: bool, http_status: int|null, json: array<mixed>|null, raw: string|null, error: string|null, duration_ms: int}  $result
     */
    public function log(
        string $operation,
        string $method,
        string $endpoint,
        ?array $payload,
        array $result,
        ?int $deliveryShipmentId = null,
        ?int $triggeredBy = null,
    ): void {
        try {
            ApiShipRequest::create([
                'delivery_shipment_id' => $deliveryShipmentId,
                'operation' => $operation,
                'method' => $method,
                'endpoint' => $endpoint,
                'request_payload' => $payload === null ? null : $this->mask($payload),
                'response_payload' => is_array($result['json']) ? $result['json'] : null,
                // Сырой ответ обрезаем: сюда попадают HTML-заглушки балансировщика
                // и бинарные этикетки, а журнал не архив документов.
                'response_raw' => $result['raw'] === null ? null : mb_substr($result['raw'], 0, 5000),
                'http_status' => $result['http_status'],
                'error_message' => $result['error'],
                'duration_ms' => $result['duration_ms'],
                'triggered_by' => $triggeredBy,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ApiShip: не удалось записать вызов в журнал', [
                'operation' => $operation,
                'endpoint' => $endpoint,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Рекурсивно вырезает секреты из тела запроса.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function mask(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->mask($value);

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), array_map('strtolower', self::SECRET_KEYS), true)) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }
}
