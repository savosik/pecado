<?php

namespace App\Services\Feed;

use App\Models\ProductExport;
use App\Services\ProductExport\Presets\YandexMarketFeedPreset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сборщик публичного YML-фида для Яндекс.Маркета.
 *
 * Генерирует единый файл `storage/app/feeds/yandex-market.yml` с розничными
 * ценами, без привязки к партнёрским выгрузкам. Файл лежит вне
 * `storage/app/exports/`, поэтому `exports:cleanup` его не трогает.
 *
 * Запись атомарная (tmp + rename) и под блокировкой — параллельные вызовы
 * (cron + первый HTTP-запрос при холодном кэше) не порождают гонок и не
 * отдают полуготовый файл.
 */
class YandexMarketFeedBuilder
{
    /**
     * Имя файла фида. Префикс `feed_` — зарезервированный: exports:cleanup
     * не трогает такие файлы (см. CleanupProductExports).
     */
    public const FILE = 'feed_yandex-market.yml';

    /**
     * Internal-путь для X-Accel-Redirect. nginx location /__internal_exports/
     * ссылается на storage/app/exports/, поэтому файл лежит именно там —
     * это даёт быструю отдачу через nginx, минуя PHP-FPM.
     */
    public const XACCEL_URI = '/__internal_exports/'.self::FILE;

    /** Ключ блокировки на время генерации. */
    protected const LOCK_KEY = 'feed:yandex-market:build';

    /**
     * Абсолютный путь к файлу фида. Каталог exports/ переиспользуется ради
     * готовой X-Accel-локации nginx; от очистки файл защищён префиксом feed_.
     */
    public function path(): string
    {
        return storage_path('app/exports/'.self::FILE);
    }

    /**
     * Существует ли непустой файл фида.
     */
    public function exists(): bool
    {
        $path = $this->path();

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Сгенерировать (перезаписать) файл фида. Возвращает путь к файлу.
     *
     * Под блокировкой: если другой процесс уже строит фид, ждём его до 120 с
     * и возвращаем готовый путь, не запуская вторую генерацию.
     */
    public function build(): string
    {
        $lock = Cache::lock(self::LOCK_KEY, 300);

        // block(120): дождаться чужой генерации, а не строить параллельно.
        $lock->block(120, function () {
            $this->generate();
        });

        return $this->path();
    }

    /**
     * Убедиться, что файл есть; собрать только при отсутствии.
     */
    public function ensure(): string
    {
        if ($this->exists()) {
            return $this->path();
        }

        return $this->build();
    }

    /**
     * Собственно генерация: пишем в tmp и атомарно переименовываем.
     */
    protected function generate(): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $preset = app(YandexMarketFeedPreset::class);

        // Транзиентная (несохранённая) выгрузка: client_user_id = null →
        // розничные цены гостя. В БД ничего не пишем.
        $export = new ProductExport;
        $export->client_user_id = null;
        $export->filters = [];
        $export->fields = [];

        $tmp = $this->path().'.tmp.'.getmypid();
        $stream = fopen($tmp, 'w');

        try {
            $preset->writeToStream($stream, $export);
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($tmp);
            throw $e;
        }

        fclose($stream);
        rename($tmp, $this->path());

        Log::info('feed.yandex_market.built', [
            'rows' => $preset->getRowsProcessed(),
            'bytes' => (int) @filesize($this->path()),
        ]);
    }
}
