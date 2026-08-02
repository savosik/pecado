<?php

namespace App\Support;

/**
 * Общие настройки HTTP-клиента для запросов к OpenRouter.
 *
 * На проде OpenRouter (Cloudflare) отвечает 403 на запросы с внешнего IP сервера,
 * поэтому трафик уводится через прокси в SSH-туннеле (контейнер outbound-proxy).
 * Если OPENROUTER_PROXY не задан — ходим напрямую.
 */
class OpenRouter
{
    /**
     * Опции Guzzle: таймаут и прокси, если он настроен.
     *
     * @return array<string, mixed>
     */
    public static function guzzleOptions(int $timeout): array
    {
        return array_filter([
            'timeout' => $timeout,
            'proxy' => config('normalizer.proxy'),
        ]);
    }

    /**
     * Опции для HTTP-клиента Laravel (Http::withOptions).
     *
     * @return array<string, mixed>
     */
    public static function httpOptions(): array
    {
        return array_filter([
            'proxy' => config('normalizer.proxy'),
        ]);
    }
}
