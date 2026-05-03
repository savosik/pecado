<?php

namespace App\Services\DaData;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DaDataClient
{
    private string $apiKey;

    private string $secretKey;

    private string $baseUrl;

    private int $cacheTtl;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('services.dadata.api_key');
        $this->secretKey = (string) config('services.dadata.secret_key');
        $this->baseUrl = rtrim((string) config('services.dadata.suggestions_url'), '/');
        $this->cacheTtl = (int) config('services.dadata.cache_ttl', 86400);
        $this->timeout = (int) config('services.dadata.request_timeout', 5);
    }

    /**
     * Подсказки по компаниям (по названию или ИНН).
     *
     * @return array<int, array<string, mixed>> массив suggestion-объектов из DaData
     */
    public function suggestParty(string $query, int $count = 10): array
    {
        $response = $this->post('/suggest/party', [
            'query' => $query,
            'count' => max(1, min($count, 20)),
        ]);

        return $response['suggestions'] ?? [];
    }

    /**
     * Точное получение реквизитов по ИНН (с опциональным КПП).
     * Кэшируется в Redis на cache_ttl секунд.
     *
     * @return array<string, mixed>|null suggestion-объект или null, если компания не найдена
     */
    public function findPartyByInn(string $inn, ?string $kpp = null): ?array
    {
        $cacheKey = "dadata:party:{$inn}".($kpp !== null ? ":{$kpp}" : '');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($inn, $kpp) {
            $payload = ['query' => $inn];
            if ($kpp !== null && $kpp !== '') {
                $payload['kpp'] = $kpp;
            }

            $response = $this->post('/findById/party', $payload);
            $suggestions = $response['suggestions'] ?? [];

            return $suggestions[0] ?? null;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        if ($this->apiKey === '') {
            throw new DaDataException('DADATA_API_KEY не настроен.');
        }

        $url = $this->baseUrl.$path;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token '.$this->apiKey,
                'X-Secret' => $this->secretKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            throw new DaDataException("Сбой запроса к DaData {$path}: {$e->getMessage()}", 0, $e);
        }

        $this->logDailyLimit($response);

        if ($response->failed()) {
            throw new DaDataException("DaData ответил {$response->status()} на {$path}: ".$response->body());
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new DaDataException("DaData вернул не-JSON-ответ на {$path}.");
        }

        return $data;
    }

    private function logDailyLimit(Response $response): void
    {
        $remaining = $response->header('X-Ratelimit-Remaining')
            ?: $response->header('x-ratelimit-remaining');

        if ($remaining === '') {
            return;
        }

        Log::info('DaData дневной лимит', ['remaining' => $remaining]);
    }
}
