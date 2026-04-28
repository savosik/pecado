<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Универсальный fuzzy-источник для контроллеров документов кабинета.
 * Возвращает список id документов (order/return/shipment), у которых
 * среди позиций нашлось fuzzy-совпадение по `product_name_snapshot`/
 * `brand_name_snapshot` через Meilisearch.
 *
 * Жёсткий scope `user_id` навешивается DB-фильтром поверх Scout-результата,
 * чтобы не зависеть от настроек filterableAttributes индекса.
 */
class FuzzyDocumentMatcher
{
    public static function isApplicable(string $search, string $queryType): bool
    {
        if (! (bool) config('search-cabinet.fuzzy_documents')) {
            return false;
        }

        if ($queryType !== QueryRouter::TYPE_TEXT) {
            return false;
        }

        return mb_strlen(trim($search)) >= 2;
    }

    /**
     * @param  class-string  $itemModelClass  модель с Laravel\Scout\Searchable trait
     * @return array<int, int>
     */
    public static function findDocumentIds(
        string $search,
        string $itemModelClass,
        string $documentForeignKey,
        string $parentRelation,
        int $userId,
    ): array {
        $limit = (int) config('search-cabinet.fuzzy_documents_limit', 500);

        /** @var array<int, int|string> $itemIds */
        $itemIds = $itemModelClass::search($search)
            ->take($limit)
            ->keys()
            ->all();

        if (empty($itemIds)) {
            return [];
        }

        return $itemModelClass::query()
            ->whereIn('id', $itemIds)
            ->whereHas($parentRelation, fn (Builder $q) => $q->where('user_id', $userId))
            ->pluck($documentForeignKey)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
