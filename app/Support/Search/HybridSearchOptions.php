<?php

namespace App\Support\Search;

/**
 * Единая точка построения опций гибридного (векторного) поиска Meilisearch.
 *
 * Раньше метод `getHybridSearchOptions()` был скопирован в трёх местах
 * (SearchController, Api\Content\ProductController, SimilarProductsService).
 * Теперь все они дергают этот хелпер.
 *
 * Опции передаются в Scout `Builder::options()` и уходят в Meilisearch как
 * параметр `hybrid` (embedder + semanticRatio). Если гибрид выключен
 * (`SEARCH_HYBRID_ENABLED=false`) — возвращается null, и поиск идёт чисто
 * полнотекстовым (keyword).
 */
class HybridSearchOptions
{
    /**
     * Опции гибридного поиска для Scout `->options()`.
     * Null — когда гибрид выключен (keyword-only).
     *
     * @return array{hybrid: array{embedder: string, semanticRatio: float}}|null
     */
    public static function forProducts(): ?array
    {
        if (! config('search.hybrid.enabled')) {
            return null;
        }

        return [
            'hybrid' => [
                'embedder' => config('search.hybrid.embedder'),
                'semanticRatio' => (float) config('search.hybrid.semantic_ratio'),
            ],
        ];
    }

    /**
     * Нужно ли после keyword-поиска повторять запрос с семантикой.
     *
     * Keyword-first: семантику (hybrid) подмешиваем только когда чистый
     * полнотекстовый поиск вернул меньше `fallback_min_results` результатов
     * (опечатки/синонимы). Если гибрид выключен — fallback-а нет.
     */
    public static function shouldFallback(int $keywordResultCount): bool
    {
        if (self::forProducts() === null) {
            return false;
        }

        return $keywordResultCount < (int) config('search.hybrid.fallback_min_results', 1);
    }
}
