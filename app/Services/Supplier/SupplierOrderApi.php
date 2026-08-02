<?php

namespace App\Services\Supplier;

use Illuminate\Support\Facades\Http;

/**
 * Транспорт Customer API поставщика (Andrey Company / sex-opt.ru).
 *
 * Формат запроса взят из официального клиента поставщика (`Andrey_Api`):
 * POST-поле `post` с JSON вида `{api_key, method, data}`. Ответ — JSON,
 * в котором всегда есть `result` (success|error) и `transaction` (commit|rollback).
 *
 * Класс намеренно ничего не знает про заказы: он только отправляет метод
 * и возвращает разобранный ответ. Бизнес-логика — в SupplierPreorderSender.
 */
class SupplierOrderApi
{
    /**
     * Вызвать метод API.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, http_status: int|null, json: array<string, mixed>|null, raw: string|null, error: string|null, duration_ms: int}
     */
    public function call(string $method, array $data): array
    {
        $url = (string) config('services.sex_opt.order_api_url');
        $apiKey = (string) config('services.sex_opt.order_api_key');
        $timeout = (int) config('services.sex_opt.order_api_timeout', 30);

        $startedAt = microtime(true);

        if ($apiKey === '') {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'error' => 'Не задан ключ API поставщика (SUPPLIER_ORDER_API_KEY)',
                'duration_ms' => 0,
            ];
        }

        $body = json_encode([
            'api_key' => $apiKey,
            'method' => $method,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::asForm()
                ->connectTimeout(15)
                ->timeout($timeout)
                ->post($url, ['post' => $body]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $raw = $response->body();
        $json = json_decode($raw, true);

        if (! is_array($json)) {
            return [
                'ok' => false,
                'http_status' => $response->status(),
                'json' => null,
                'raw' => mb_substr($raw, 0, 5000),
                'error' => $response->failed()
                    ? 'HTTP '.$response->status()
                    : 'Ответ поставщика не является JSON',
                'duration_ms' => $duration,
            ];
        }

        return [
            'ok' => $response->successful(),
            'http_status' => $response->status(),
            'json' => $json,
            'raw' => null,
            'error' => $response->failed() ? 'HTTP '.$response->status() : null,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Отправить заказ поставщику (метод send_order).
     *
     * @param  array<string, int>  $items  код товара из 1С => количество
     * @return array{ok: bool, http_status: int|null, json: array<string, mixed>|null, raw: string|null, error: string|null, duration_ms: int}
     */
    public function sendOrder(array $items, string $stock, string $comment, bool $testmode, bool $rollbackOnWarnings = false): array
    {
        return $this->call('send_order', [
            'stock' => $stock,
            'comment' => $comment,
            // (object) — чтобы items всегда уехали JSON-объектом: коды товаров
            // вроде «2015» PHP превращает в целочисленные ключи, и массив
            // сериализовался бы в JSON-массив, который поставщик не поймёт.
            'items' => (object) $items,
            'testmode' => $testmode,
            'rollback_on_warnings' => $rollbackOnWarnings,
        ]);
    }
}
