<?php

namespace App\Http\Controllers\User;

use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\BuildsCatalogFacets;
use App\Http\Requests\User\SearchFilterRequest;
use App\Services\Product\CatalogFacetService;
use App\Services\Product\CatalogProductPresenter;
use App\Services\Product\ProductQueryService;
use App\Services\Search\ExactProductMatcher;
use App\Services\Search\ProductSearchQuery;
use App\Services\Search\ProductSearchResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * API-контроллер поиска товаров.
 *
 * Полный аналог каталожного API (`/api/catalog/products*`): те же фильтры,
 * фасеты, сортировки и пагинация. Разница одна — выборка ограничена набором
 * id, который вернул Meilisearch по запросу `q`, а сортировка по умолчанию
 * сохраняет его порядок релевантности.
 */
class SearchApiController extends Controller
{
    use BuildsCatalogFacets;

    public function __construct(
        private readonly CatalogFacetService $facetService,
        private readonly CatalogProductPresenter $presenter,
        private readonly ProductSearchResolver $resolver,
        private readonly ExactProductMatcher $exactMatcher,
        private readonly ProductSearchQuery $search,
    ) {}

    /**
     * Найденные товары с фильтрами и пагинацией.
     *
     * GET /api/search/products
     */
    public function products(SearchFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $resolved = $this->resolver->resolve($validated['q']);
        $ids = $resolved['ids'];

        $perPage = (int) ($validated['per_page'] ?? 20);

        if (empty($ids)) {
            return response()->json([
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
            ]);
        }

        $query = $this->searchQuery($validated, $ids);

        // Сортировка: по умолчанию — порядок релевантности Meilisearch
        $sort = $validated['sort'] ?? SearchFilterRequest::SORT_RELEVANCE;
        if ($sort === SearchFilterRequest::SORT_RELEVANCE) {
            $this->search->applyRelevanceOrder($query, $ids);
        } else {
            (CatalogSort::from($sort))->apply($query);
        }

        $query->with(ProductQueryService::productEagerLoads());

        // select('products.*') нужен, чтобы selectRaw('0 as ...') в withRegionStockSums
        // не перезаписал стандартный SELECT *.
        $query->select('products.*');
        ProductQueryService::withRegionStockSums($query);

        $paginated = $query->paginate($perPage);

        // «Точного совпадения не найдено — показаны похожие»: запрос не встречается
        // буквально ни в названии, ни в артикуле/коде/штрихкоде/бренде найденных товаров.
        $hasExact = $resolved['exact']
            || $this->exactMatcher->hasLiteralMatch($paginated->getCollection(), $validated['q']);

        return response()->json([
            'data' => $this->presenter->present($paginated->getCollection()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'no_exact_match' => $paginated->total() > 0 && ! $hasExact,
                // Выдача Meilisearch упёрлась в потолок — показанное не покрывает
                // все совпадения, фронт может предложить уточнить запрос.
                'capped' => count($ids) >= ProductSearchResolver::MAX_IDS,
            ],
        ]);
    }

    /**
     * Фасеты фильтров в пределах найденного набора товаров.
     *
     * GET /api/search/products/facets
     */
    public function facets(SearchFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = $this->resolver->resolve($validated['q'])['ids'];

        if (empty($ids)) {
            return response()->json(['brands' => [], 'categories' => [], 'attributes' => []]);
        }

        return response()->json($this->assembleFacets(
            $validated,
            fn (array $filters) => $this->searchQuery($filters, $ids),
        ));
    }

    /**
     * Ценовые интервалы в пределах найденного набора товаров.
     *
     * GET /api/search/products/price-intervals
     */
    public function priceIntervals(SearchFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = $this->resolver->resolve($validated['q'])['ids'];

        // Убираем ценовые фильтры, чтобы показать полный диапазон
        unset($validated['price_min'], $validated['price_max']);

        if (empty($ids)) {
            return response()->json(['min' => 0, 'max' => 0, 'buckets' => []]);
        }

        return response()->json(
            $this->buildPriceIntervals($this->searchQuery($validated, $ids))
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────────

    /**
     * Базовый запрос каталога, ограниченный найденными товарами — общим сервисом
     * поиска (им же пользуется клиентский API v1).
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, int>  $ids
     */
    private function searchQuery(array $validated, array $ids): Builder
    {
        return $this->search->builder($validated, $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMeta(int $perPage): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
            'no_exact_match' => false,
            'capped' => false,
        ];
    }
}
