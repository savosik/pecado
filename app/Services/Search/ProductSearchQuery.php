<?php

namespace App\Services\Search;

use App\Services\Product\CatalogQueryBuilder;
use App\Services\Product\ProductQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Выборка каталога, ограниченная выдачей поиска, в порядке релевантности.
 *
 * Общая часть поискового API кабинета и клиентского API v1: набор id отдаёт
 * {@see ProductSearchResolver} (точное совпадение по коду либо Meilisearch),
 * здесь он превращается в запрос каталога с сохранённым порядком выдачи.
 * Товары без остатков не прячем: искали конкретную позицию — должны найти.
 */
class ProductSearchQuery
{
    public function __construct(
        private readonly ProductSearchResolver $resolver,
        private readonly CatalogQueryBuilder $queryBuilder,
        private readonly ExactProductMatcher $exactMatcher,
    ) {}

    /**
     * Базовый запрос каталога, ограниченный найденными товарами.
     *
     * Параметр `q` из фильтров убираем: релевантность уже учтена набором id,
     * а LIKE-поиск каталога отбросил бы находки Meilisearch по опечаткам.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $ids
     */
    public function builder(array $filters, array $ids): Builder
    {
        unset($filters['q']);

        // whereIntegerInRaw, а не whereIn: до 1000 id, биндинги упёрлись бы
        // в лимит параметров драйвера.
        return $this->queryBuilder->build($filters, hideUnavailableByDefault: false)
            ->whereIntegerInRaw('products.id', $ids);
    }

    /**
     * Сортировка по релевантности: сначала в наличии, затем под предзаказ, затем
     * отсутствующие; внутри групп — порядок, в котором ответил Meilisearch.
     *
     * Требует алиасов primary_stock / preorder_stock (withRegionStockSums).
     *
     * @param  array<int, int>  $ids
     */
    public function applyRelevanceOrder(Builder $query, array $ids): void
    {
        $query->reorder();
        $query->orderByRaw('(CASE WHEN primary_stock > 0 THEN 0 WHEN preorder_stock > 0 THEN 1 ELSE 2 END)');
        $query->orderByRaw($this->relevanceExpression($query, $ids));
    }

    /**
     * Страница результатов для машинного потребителя: без фасетов и фильтров,
     * с порядком релевантности.
     *
     * @return array{paginator: LengthAwarePaginator, exact: bool, capped: bool}
     */
    public function paginate(string $q, int $perPage, int $page = 1): array
    {
        $resolved = $this->resolver->resolve($q);
        $ids = $resolved['ids'];

        if ($ids === []) {
            return [
                'paginator' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page),
                'exact' => false,
                'capped' => false,
            ];
        }

        $query = $this->builder([], $ids);
        $query->select('products.*');
        ProductQueryService::withRegionStockSums($query);
        $this->applyRelevanceOrder($query, $ids);
        $query->with(['brand']);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'paginator' => $paginator,
            'exact' => $resolved['exact'] || $this->exactMatcher->hasLiteralMatch($paginator->getCollection(), $q),
            'capped' => count($ids) >= ProductSearchResolver::MAX_IDS,
        ];
    }

    /**
     * SQL-выражение позиции товара в выдаче Meilisearch. id подставляются в текст
     * запроса как целые (не биндинги) — их до 1000.
     *
     * @param  array<int, int>  $ids
     */
    public function relevanceExpression(Builder $query, array $ids): string
    {
        $ids = array_map('intval', array_values($ids));

        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return 'FIELD(products.id, '.implode(',', $ids).')';
        }

        $cases = '';

        foreach ($ids as $position => $id) {
            $cases .= " WHEN {$id} THEN {$position}";
        }

        return '(CASE products.id'.$cases.' ELSE '.count($ids).' END)';
    }
}
