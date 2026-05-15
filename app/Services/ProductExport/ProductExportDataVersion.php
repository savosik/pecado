<?php

namespace App\Services\ProductExport;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Глобальная «версия данных каталога» — таймштамп последнего изменения,
 * влияющего на содержимое выгрузок (товары, цены, остатки).
 *
 * Why: раньше exports:warm каждые 15 минут перегенерировал все активные
 * выгрузки независимо от того, менялось ли что-то с момента последней
 * генерации. На десятках партнёров это огромная фоновая нагрузка на пустом
 * месте. Теперь warmup сравнивает ProductExport.data_version_at с этой
 * меткой и пропускает выгрузки, у которых файл уже отражает текущую версию.
 *
 * Bump'ается из ERP-handler'ов (HandleStockUpdated, HandlePriceUpdated,
 * HandleIndividualPricesReady) и Product observer-а. Один cache->put в
 * Redis — пренебрежимая нагрузка по сравнению с экономией.
 */
class ProductExportDataVersion
{
    private const CACHE_KEY = 'product_export:data_version';

    public function __construct(private Repository $cache) {}

    /**
     * Текущая версия данных. Если ещё не было ни одного bump'а
     * (свежий деплой / очищенный Redis) — возвращает epoch 0, чтобы любая
     * существующая data_version_at считалась актуальной (нечего инвалидировать).
     */
    public function current(): CarbonImmutable
    {
        $raw = $this->cache->get(self::CACHE_KEY);
        if (! $raw) {
            return CarbonImmutable::createFromTimestamp(0);
        }

        return CarbonImmutable::parse($raw);
    }

    /**
     * Помечает «данные каталога обновились». Все ранее сгенерированные
     * выгрузки с data_version_at < now станут считаться устаревшими.
     *
     * Дешёвая операция (один Redis SET без TTL), безопасно дёргать
     * из горячих ERP-handler-ов.
     */
    public function bump(): void
    {
        $this->cache->forever(self::CACHE_KEY, now()->toIso8601String());
    }
}
