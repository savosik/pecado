<?php

namespace App\Http\Controllers\User;

use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProductFilterRequest;
use App\Models\Product;
use App\Services\Product\CatalogFacetService;
use App\Services\Product\ProductQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * API-контроллер каталога товаров.
 *
 * Эндпоинты для получения JSON-данных: список товаров с пагинацией,
 * фасеты фильтров (бренды, категории, атрибуты) и ценовые интервалы.
 */
class CatalogApiController extends Controller
{
    public function __construct(
        private readonly CatalogFacetService $facetService,
    ) {}

    /**
     * Список товаров с пагинацией.
     *
     * GET /api/catalog/products
     */
    public function products(ProductFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = $this->buildBaseQuery($validated);

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

        // Преобразование в массивы
        $products = $paginated->getCollection()
            ->map(fn (Product $product) => ProductQueryService::productToArray($product))
            ->values()
            ->toArray();

        // Обогащение скидками и конвертация валют
        $products = ProductQueryService::enrichProductsWithDiscounts($products);
        $products = ProductQueryService::convertProductsPrices($products);

        // Добавляем is_favorited для авторизованных пользователей
        $user = Auth::user();
        if ($user) {
            $productIds = collect($products)->pluck('id')->toArray();
            $favoritedIds = DB::table('favorites')
                ->where('user_id', $user->id)
                ->whereIn('product_id', $productIds)
                ->pluck('product_id')
                ->flip()
                ->toArray();

            $products = array_map(function ($product) use ($favoritedIds) {
                $product['is_favorited'] = isset($favoritedIds[$product['id']]);
                return $product;
            }, $products);
        } else {
            // Для гостей: убираем ценовые и складские данные из ответа
            $products = array_map(function ($product) {
                $product['is_favorited'] = false;
                unset(
                    $product['base_price'],
                    $product['sale_price'],
                    $product['discount_percentage'],
                    $product['stock_quantity'],
                    $product['preorder_quantity'],
                );
                return $product;
            }, $products);
        }

        return response()->json([
            'data' => $products,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
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
        $validated = $request->validated();

        // Для каждой группы фасетов строим запрос БЕЗ фильтра этой группы.
        // Это реализует паттерн «OR внутри группы, AND между группами»:
        // выбрав «Красный», пользователь всё ещё видит «Синий» и «Зелёный»,
        // но при этом фасеты Материала учитывают выбранный Цвет.
        $withoutBrands = $validated;
        unset($withoutBrands['brand_ids']);

        $withoutCategories = $validated;
        unset($withoutCategories['category_ids']);

        // Для атрибутов — исключаем attribute_value_ids из базового запроса,
        // но передаём их в getAttributeFacets для per-attribute exclusion.
        $selectedAttributeValueIds = array_map(
            'intval',
            $validated['attribute_value_ids'] ?? [],
        );
        $withoutAttributes = $validated;
        unset($withoutAttributes['attribute_value_ids']);

        return response()->json([
            'brands'     => $this->facetService->getBrandFacets($this->buildBaseQuery($withoutBrands)),
            'categories' => $this->facetService->getCategoryFacets($this->buildBaseQuery($withoutCategories)),
            'attributes' => $this->facetService->getAttributeFacets(
                $this->buildBaseQuery($withoutAttributes),
                $selectedAttributeValueIds,
            ),
        ]);
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

        $query = $this->buildBaseQuery($validated);

        return response()->json(
            $this->facetService->getPriceIntervals($query),
        );
    }

    /**
     * Построить базовый запрос с применением всех фильтров из validated-параметров.
     */
    private function buildBaseQuery(array $validated): Builder
    {
        $query = Product::query();
        $user = Auth::user();

        // Поиск
        if (!empty($validated['q'])) {
            $query->search($validated['q']);
        }

        // Категория (одиночная, из маршрута)
        if (!empty($validated['category_id'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategory((int) $validated['category_id'], (bool) $descendants);
        }

        // Категории (множественные)
        if (!empty($validated['category_ids'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategories(
                array_map('intval', $validated['category_ids']),
                (bool) $descendants,
            );
        }

        // Бренды
        if (!empty($validated['brand_ids'])) {
            $query->inBrands(array_map('intval', $validated['brand_ids']));
        }

        // Подборки (коллекции)
        if (!empty($validated['collection_ids'])) {
            $query->inCollections(array_map('intval', $validated['collection_ids']));
        }

        // Цена
        $priceMin = $validated['price_min'] ?? null;
        $priceMax = $validated['price_max'] ?? null;
        if ($priceMin !== null || $priceMax !== null) {
            $query->byPrice(
                $priceMin !== null ? (float) $priceMin : null,
                $priceMax !== null ? (float) $priceMax : null,
            );
        }

        // Наличие
        if (!empty($validated['in_stock_mode'])) {
            $query->inStock($validated['in_stock_mode'], $user?->region_id);
        } elseif (!empty($validated['in_stock'])) {
            $query->inStock('instock', $user?->region_id);
        }

        // Скидка (in_sale=1 → только со скидкой; in_sale=0 или отсутствует → без фильтра)
        if (!empty($validated['in_sale'])) {
            $query->inSale(true);
        }

        // Избранное
        if (!empty($validated['in_favourites']) && $user) {
            $query->inFavourites($user->id);
        }

        // Атрибуты
        if (!empty($validated['attribute_value_ids'])) {
            $any = (bool) ($validated['attribute_any'] ?? false);
            $query->byAttributes(
                array_map('intval', $validated['attribute_value_ids']),
                $any,
            );
        }

        return $query;
    }
}
