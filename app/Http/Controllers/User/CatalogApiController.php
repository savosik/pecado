<?php

namespace App\Http\Controllers\User;

use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\BuildsCatalogFacets;
use App\Http\Requests\User\ProductFilterRequest;
use App\Services\Product\CatalogFacetService;
use App\Services\Product\CatalogProductPresenter;
use App\Services\Product\CatalogQueryBuilder;
use App\Services\Product\ProductQueryService;
use Illuminate\Http\JsonResponse;

/**
 * API-контроллер каталога товаров.
 *
 * Эндпоинты для получения JSON-данных: список товаров с пагинацией,
 * фасеты фильтров (бренды, категории, атрибуты) и ценовые интервалы.
 */
class CatalogApiController extends Controller
{
    use BuildsCatalogFacets;

    public function __construct(
        private readonly CatalogFacetService $facetService,
        private readonly CatalogQueryBuilder $queryBuilder,
        private readonly CatalogProductPresenter $presenter,
    ) {}

    /**
     * Список товаров с пагинацией.
     *
     * GET /api/catalog/products
     */
    public function products(ProductFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = $this->queryBuilder->build($validated);

        // Сортировка
        $sort = CatalogSort::tryFrom($validated['sort'] ?? '') ?? CatalogSort::Newest;
        $sort->apply($query);

        // Eager-загрузка
        $query->with(ProductQueryService::productEagerLoads());

        // Добавляем остатки по региону
        // select('products.*') нужен, чтобы selectRaw('0 as ...') в withRegionStockSums
        // не перезаписал стандартный SELECT *.
        $query->select('products.*');
        ProductQueryService::withRegionStockSums($query);

        // Пагинация
        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginated = $query->paginate($perPage);

        $products = $this->presenter->present($paginated->getCollection());

        return response()->json([
            'data' => $products,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
        ]);
    }

    /**
     * Фасеты фильтров: бренды, категории, атрибуты.
     *
     * GET /api/catalog/products/facets
     */
    public function facets(ProductFilterRequest $request): JsonResponse
    {
        return response()->json($this->assembleFacets(
            $request->validated(),
            fn (array $filters) => $this->queryBuilder->build($filters),
        ));
    }

    /**
     * Ценовые интервалы: min, max и бакеты.
     *
     * GET /api/catalog/products/price-intervals
     * Строится без фильтров price_min/price_max, чтобы показать полный диапазон.
     */
    public function priceIntervals(ProductFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Убираем ценовые фильтры, чтобы показать полный диапазон
        unset($validated['price_min'], $validated['price_max']);

        return response()->json(
            $this->buildPriceIntervals($this->queryBuilder->build($validated))
        );
    }
}
