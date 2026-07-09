<?php

namespace App\Http\Controllers;

use App\Services\Feed\YandexMarketFeedBuilder;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Публичный YML-фид Яндекс.Маркета: GET /feed/yandex-market.yml
 *
 * Файл генерируется по расписанию ({@see \App\Console\Commands\BuildYandexMarketFeed}).
 * Если кэш ещё не собран (первый запрос после деплоя) — собираем на лету.
 * Отдаём потоково через BinaryFileResponse с гарантированным XML-типом.
 */
class YandexMarketFeedController extends Controller
{
    public function show(YandexMarketFeedBuilder $builder): BinaryFileResponse
    {
        $path = $builder->ensure();

        return response()->file($path, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="yandex-market.yml"',
        ]);
    }
}
