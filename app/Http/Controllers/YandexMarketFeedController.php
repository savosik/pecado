<?php

namespace App\Http\Controllers;

use App\Services\Feed\YandexMarketFeedBuilder;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Публичный YML-фид Яндекс.Маркета: GET /feed/yandex-market.yml
 *
 * Файл генерируется по расписанию ({@see \App\Console\Commands\BuildYandexMarketFeed}).
 * Если кэш ещё не собран (первый запрос после деплоя) — собираем на лету.
 *
 * Отдаём через X-Accel-Redirect: nginx сам стримит файл с диска (location
 * /__internal_exports/), минуя PHP-FPM. Фид ~9 МБ — через PHP-FPM отдача
 * занимала 15+ с, через nginx — доли секунды. На не-nginx (тесты, встроенный
 * сервер) X-Accel игнорируется, поэтому там fallback на BinaryFileResponse.
 */
class YandexMarketFeedController extends Controller
{
    public function show(YandexMarketFeedBuilder $builder): Response|BinaryFileResponse
    {
        $path = $builder->ensure();

        $headers = [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="yandex-market.yml"',
        ];

        if ($this->shouldUseXAccelRedirect()) {
            $headers['X-Accel-Redirect'] = YandexMarketFeedBuilder::XACCEL_URI;

            return response('', 200, $headers);
        }

        return response()->file($path, $headers);
    }

    /**
     * X-Accel-Redirect только под nginx (см. ProductExportDownloadController).
     */
    protected function shouldUseXAccelRedirect(): bool
    {
        return config('app.env') !== 'testing'
            && str_starts_with((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'nginx');
    }
}
