<?php

namespace App\Support\Search;

use App\Models\Product;

/**
 * Универсальный fuzzy-источник для товарных эндпойнтов кабинета.
 * Возвращает список id товаров, найденных Meilisearch-поиском по `Product`.
 *
 * Скоуп пользователя (favorites/cart) навешивается контроллером поверх результата —
 * сам Scout-запрос ищет глобально по индексу Product.
 */
class FuzzyProductMatcher
{
    /**
     * Fuzzy полезен для естественного текста и для SKU-подобных запросов
     * (`addidas`, `nayk`, `nike-air`) — там могут быть опечатки и транслит.
     * Точные идентификаторы (штрихкод, ИНН, UUID, номер документа) обрабатываются
     * прямыми поисками, fuzzy для них только засоряет выдачу.
     */
    private const SUPPORTED_TYPES = [
        QueryRouter::TYPE_TEXT,
        QueryRouter::TYPE_SKU_LIKE,
    ];

    public static function isApplicable(string $search, string $queryType): bool
    {
        if (! (bool) config('search-cabinet.fuzzy_products')) {
            return false;
        }

        if (! in_array($queryType, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        return mb_strlen(trim($search)) >= 2;
    }

    /**
     * @return array<int, int>
     */
    public static function findProductIds(string $search): array
    {
        $limit = (int) config('search-cabinet.fuzzy_products_limit', 500);

        /** @var array<int, int|string> $ids */
        $ids = Product::search($search)
            ->take($limit)
            ->keys()
            ->all();

        return array_values(array_map('intval', $ids));
    }
}
