<?php

namespace App\Console\Commands;

use App\Services\Feed\YandexMarketFeedBuilder;
use Illuminate\Console\Command;

/**
 * Регенерация публичного YML-фида Яндекс.Маркета.
 *
 * Запускается по расписанию (см. routes/console.php), чтобы Яндекс, забирая
 * фид по своему графику, всегда получал свежие цены и остатки.
 */
class BuildYandexMarketFeed extends Command
{
    protected $signature = 'feed:build-yandex';

    protected $description = 'Сгенерировать публичный YML-фид Яндекс.Маркета (storage/app/feeds/yandex-market.yml)';

    public function handle(YandexMarketFeedBuilder $builder): int
    {
        $started = microtime(true);
        $path = $builder->build();
        $ms = (int) ((microtime(true) - $started) * 1000);

        $this->info(sprintf(
            'YML-фид собран за %d мс: %s (%.2f МБ)',
            $ms,
            $path,
            (int) @filesize($path) / 1024 / 1024,
        ));

        return self::SUCCESS;
    }
}
