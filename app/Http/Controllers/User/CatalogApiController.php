<?php

namespace App\Http\Controllers\User;

use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProductFilterRequest;
use App\Models\Product;
use App\Models\Region;
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

        // Маркеры акции (region-aware): участие в акции и «выдаётся как промо-позиция».
        // Обогатитель общий для каталога, карточки товара, подборок, избранного и поиска —
        // бейдж в сетке обязан совпадать с тем, что видно на детальной странице.
        $user = Auth::user();
        $products = ProductQueryService::enrichProductsWithPromotions($products);

        // Маркер уценки (некондиции) — еле заметный значок в карточке каталога.
        // Булев флаг, не ценовой: отдаётся и гостям.
        $products = ProductQueryService::enrichProductsWithDefects($products);

        // Добавляем is_favorited для авторизованных пользователей
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
        $validated = $request->validated();

        $withoutBrands = $validated;
        unset($withoutBrands['brand_ids']);

        $withoutCategories = $validated;
        unset($withoutCategories['category_ids']);

        $selectedBrandIds = array_map(
            'intval',
            $validated['brand_ids'] ?? [],
        );
        $selectedCategoryIds = array_map(
            'intval',
            $validated['category_ids'] ?? [],
        );
        if (! empty($validated['category_id']) && ! in_array((int) $validated['category_id'], $selectedCategoryIds)) {
            $selectedCategoryIds[] = (int) $validated['category_id'];
        }

        $selectedAttributeValueIds = array_map(
            'intval',
            $validated['attribute_value_ids'] ?? [],
        );
        $withoutAttributes = $validated;
        unset($withoutAttributes['attribute_value_ids']);
        unset($withoutAttributes['attribute_inline_filters']);

        return response()->json([
            'brands' => $this->facetService->getBrandFacets($this->buildBaseQuery($withoutBrands), $selectedBrandIds),
            'categories' => $this->facetService->getCategoryFacets($this->buildBaseQuery($withoutCategories), $selectedCategoryIds),
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

        $intervals = $this->facetService->getPriceIntervals($query);

        // Конвертируем цены в валюту пользователя
        $user = Auth::user();
        if ($user && $user->region?->currency && ! $user->region->currency->is_base) {
            $currencyService = app(\App\Services\CurrencyService::class);
            $currency = $user->region->currency;

            $intervals['min'] = $currencyService->convertFromBase($intervals['min'], $currency);
            $intervals['max'] = $currencyService->convertFromBase($intervals['max'], $currency);

            foreach ($intervals['buckets'] as &$bucket) {
                $bucket['from'] = $currencyService->convertFromBase($bucket['from'], $currency);
                $bucket['to'] = $currencyService->convertFromBase($bucket['to'], $currency);
            }
            unset($bucket);
        }

        return response()->json($intervals);
    }

    /**
     * Построить базовый запрос с применением всех фильтров из validated-параметров.
     */
    private function buildBaseQuery(array $validated): Builder
    {
        $query = Product::query();
        $user = Auth::user();
        // Гость трактуется так, будто у него уже назначен регион по умолчанию —
        // это обеспечивает консистентность счётчиков фильтров и списка товаров.
        $regionId = $user !== null ? $user->region_id : null;
        $regionId = $regionId ?? Region::defaultId();

        // Исключаем товары из неактивных категорий
        $query->where(function ($q) {
            $q->whereNull('category_id')
                ->orWhereHas('category', fn ($cq) => $cq->where('is_active', true));
        });

        // Поиск
        if (! empty($validated['q'])) {
            $query->search($validated['q']);
        }

        // Категория (одиночная, из маршрута)
        if (! empty($validated['category_id'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategory((int) $validated['category_id'], (bool) $descendants);
        }

        // Категории (множественные)
        if (! empty($validated['category_ids'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategories(
                array_map('intval', $validated['category_ids']),
                (bool) $descendants,
            );
        }

        // Бренды
        if (! empty($validated['brand_ids'])) {
            $query->inBrands(array_map('intval', $validated['brand_ids']));
        }

        // Подборки (коллекции)
        if (! empty($validated['collection_ids'])) {
            $query->inCollections(array_map('intval', $validated['collection_ids']));
        }

        // Цена (конвертируем из валюты пользователя в базовую для запроса к base_price)
        $priceMin = $validated['price_min'] ?? null;
        $priceMax = $validated['price_max'] ?? null;
        if ($priceMin !== null || $priceMax !== null) {
            $minVal = $priceMin !== null ? (float) $priceMin : null;
            $maxVal = $priceMax !== null ? (float) $priceMax : null;

            // Если у пользователя не базовая валюта — конвертируем обратно в базовую
            if ($user && $user->region?->currency && ! $user->region->currency->is_base) {
                $currencyService = app(\App\Services\CurrencyService::class);
                if ($minVal !== null) {
                    $minVal = $currencyService->convertToBase($minVal, $user->region->currency);
                }
                if ($maxVal !== null) {
                    $maxVal = $currencyService->convertToBase($maxVal, $user->region->currency);
                }
            }

            $query->byPrice($minVal, $maxVal);
        }

        // Наличие
        $stockMode = $validated['in_stock_mode'] ?? null;
        if ($stockMode === 'defect') {
            // «Некондиция»: товары с партиями уценки в продаже. Складские
            // остатки региона не учитываем — у уценки свой склад некондиции.
            $query->withSellableDefects();
        } elseif (! empty($stockMode)) {
            $query->inStock($stockMode, $regionId);
        } elseif (! empty($validated['in_stock'])) {
            $query->inStock('instock', $regionId);
        } else {
            // По умолчанию скрываем товары «нет в наличии» —
            // показываем только «в наличии» и «предзаказ»
            $query->available($regionId);
        }

        // Скидка (in_sale=1 → только со скидкой; in_sale=0 или отсутствует → без фильтра)
        if (! empty($validated['in_sale'])) {
            $query->inSale(true);
        }

        // Избранное
        if (! empty($validated['in_favourites']) && $user) {
            $query->inFavourites($user->id);
        }

        // Новинки
        if (! empty($validated['is_new'])) {
            $query->where('is_new', true);
        }

        // Бестселлеры
        if (! empty($validated['is_bestseller'])) {
            $query->where('is_bestseller', true);
        }

        // Участие в акции — тот же источник, что и бейдж «Акция» в карточке
        if (! empty($validated['in_promotion'])) {
            $query->inPromotion($regionId);
        }

        // Атрибуты (select — через attribute_values)
        if (! empty($validated['attribute_value_ids'])) {
            $any = (bool) ($validated['attribute_any'] ?? false);
            $query->byAttributes(
                array_map('intval', $validated['attribute_value_ids']),
                $any,
            );
        }

        // Атрибуты (inline — number/text/boolean)
        if (! empty($validated['attribute_inline_filters'])) {
            $query->byInlineAttributes($validated['attribute_inline_filters']);
        }

        return $query;
    }
}
