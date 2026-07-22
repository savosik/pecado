<?php

namespace App\Http\Controllers\Api\Content;

use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProductFilterRequest;
use App\Models\Product;
use App\Services\Product\CatalogFacetService;
use App\Services\Product\ProductQueryService;
use App\Support\Search\HybridSearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Каталог товаров (READ-ONLY).
 *
 * Полный набор фильтров, поиск через Meilisearch (гибридный),
 * фасеты и ценовые интервалы — как во фронтенде.
 *
 * @tags Каталог — Товары
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly CatalogFacetService $facetService,
    ) {}

    /**
     * Применить region_id из запроса к текущему пользователю.
     *
     * Если агент передал ?region_id=X, подставляем его,
     * чтобы ProductQueryService показал остатки и цены этого региона.
     */
    private function applyRegionFromRequest(Request $request): void
    {
        if ($regionId = $request->input('region_id')) {
            $user = $request->user();
            if ($user) {
                $user->region_id = (int) $regionId;
            }
        }
    }

    /**
     * Список товаров с фильтрами.
     *
     * Поддерживает все фильтры каталога: бренды, категории, цена, наличие,
     * скидки, новинки, бестселлеры, атрибуты, подборки.
     *
     * @queryParam region_id integer ID региона для цен и наличия (из GET /regions)
     * @queryParam q string Поисковый запрос (Meilisearch гибридный поиск)
     * @queryParam brand_ids array Фильтр по ID брендов
     * @queryParam category_ids array Фильтр по ID категорий
     * @queryParam category_id integer Категория (с потомками)
     * @queryParam price_min number Минимальная цена
     * @queryParam price_max number Максимальная цена
     * @queryParam is_new boolean Только новинки
     * @queryParam is_bestseller boolean Только бестселлеры
     * @queryParam in_sale boolean Только со скидкой
     * @queryParam in_stock boolean Только в наличии
     * @queryParam collection_ids array Из подборки
     * @queryParam attribute_value_ids array Фильтр по атрибутам
     * @queryParam sort string Сортировка: newest, price_asc, price_desc, popular
     * @queryParam per_page integer Записей на странице (5–100). Default: 20
     */
    public function index(ProductFilterRequest $request): JsonResponse
    {
        $this->applyRegionFromRequest($request);
        $validated = $request->validated();

        $query = $this->buildBaseQuery($validated);

        // Сортировка
        $sort = CatalogSort::tryFrom($validated['sort'] ?? '') ?? CatalogSort::Newest;
        $sort->apply($query);

        // Eager-загрузка
        $query->with(ProductQueryService::productEagerLoads());
        $query->select('products.*');
        ProductQueryService::withRegionStockSums($query);

        // Пагинация
        $perPage = min(max((int) ($validated['per_page'] ?? 20), 5), 100);
        $paginated = $query->paginate($perPage);

        // Преобразование
        $products = $paginated->getCollection()
            ->map(fn (Product $product) => ProductQueryService::productToArray($product))
            ->values()
            ->toArray();

        $products = ProductQueryService::enrichProductsWithDiscounts($products);
        $products = ProductQueryService::convertProductsPrices($products);

        return response()->json([
            'data' => $products,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Детальная карточка товара.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $this->applyRegionFromRequest($request);
        $product->load(ProductQueryService::productEagerLoads());

        $data = ProductQueryService::productToArray($product);
        $data = ProductQueryService::enrichProductsWithDiscounts([$data])[0];
        $data = ProductQueryService::convertProductsPrices([$data])[0];

        // Дополнительные детали для агента
        $data['full_description'] = $product->description;
        $data['meta_title'] = $product->meta_title;
        $data['meta_description'] = $product->meta_description;
        $data['media'] = $product->getMedia('main')->map(fn ($m) => [
            'id' => $m->id,
            'url' => $m->getUrl(),
            'name' => $m->file_name,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Поиск товаров через Meilisearch (гибридный поиск).
     *
     * Использует ту же поисковую систему, что и фронтенд:
     * семантический + полнотекстовый поиск с синонимами.
     *
     * @queryParam q string required Поисковый запрос (мин. 2 символа)
     * @queryParam type string Тип: all, products, categories, brands, articles. Default: all
     * @queryParam limit integer Лимит результатов. Default: 20
     */
    public function search(Request $request): JsonResponse
    {
        $this->applyRegionFromRequest($request);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['sometimes', 'string', 'in:all,products,categories,brands,articles'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'region_id' => ['sometimes', 'integer', 'exists:regions,id'],
        ]);

        $query = $validated['q'];
        $type = $validated['type'] ?? 'all';
        $limit = $validated['limit'] ?? 20;
        $page = (int) ($validated['page'] ?? 1);

        $results = [];

        // Товары
        if ($type === 'all' || $type === 'products') {
            try {
                // Keyword-first: сначала чистый полнотекст; семантику подмешиваем
                // повтором запроса только при слабой выдаче (опечатки/синонимы).
                $paginated = $this->productSearchBuilder($query, semantic: false)
                    ->paginate($limit, 'page', $page);

                if (HybridSearchOptions::shouldFallback($paginated->total())) {
                    $paginated = $this->productSearchBuilder($query, semantic: true)
                        ->paginate($limit, 'page', $page);
                }
                $products = $paginated->getCollection()
                    ->map(fn (Product $p) => ProductQueryService::productToArray($p))
                    ->values()->toArray();

                $products = ProductQueryService::enrichProductsWithDiscounts($products);
                $products = ProductQueryService::convertProductsPrices($products);

                $results['products'] = $products;
                $results['products_meta'] = [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ];
            } catch (\Throwable) {
                $results['products'] = [];
                $results['products_meta'] = null;
            }
        }

        // Категории
        if ($type === 'all' || $type === 'categories') {
            try {
                $results['categories'] = \App\Models\Category::search($query)
                    ->take($limit)->get()
                    ->filter(fn ($c) => $c->is_active)
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])
                    ->values()->toArray();
            } catch (\Throwable) {
                $results['categories'] = [];
            }
        }

        // Бренды
        if ($type === 'all' || $type === 'brands') {
            try {
                $results['brands'] = \App\Models\Brand::search($query)
                    ->take($limit)->get()
                    ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug])
                    ->toArray();
            } catch (\Throwable) {
                $results['brands'] = [];
            }
        }

        // Статьи + Новости
        if ($type === 'all' || $type === 'articles') {
            try {
                $results['articles'] = \App\Models\Article::search($query)
                    ->query(fn ($q) => $q->published())->take($limit)->get()
                    ->map(fn ($a) => [
                        'id' => $a->id, 'title' => $a->title, 'slug' => $a->slug,
                        'type' => 'article',
                    ])->toArray();
            } catch (\Throwable) {
                $results['articles'] = [];
            }

            try {
                $results['news'] = \App\Models\News::search($query)
                    ->query(fn ($q) => $q->published())->take($limit)->get()
                    ->map(fn ($n) => [
                        'id' => $n->id, 'title' => $n->title, 'slug' => $n->slug,
                        'type' => 'news',
                    ])->toArray();
            } catch (\Throwable) {
                $results['news'] = [];
            }
        }

        return response()->json([
            'query' => $query,
            'type' => $type,
            'results' => $results,
        ]);
    }

    /**
     * Фасеты фильтров: бренды, категории, атрибуты.
     *
     * Возвращает доступные значения фильтров для текущего набора товаров.
     */
    public function facets(ProductFilterRequest $request): JsonResponse
    {
        $this->applyRegionFromRequest($request);
        $validated = $request->validated();

        $withoutBrands = $validated;
        unset($withoutBrands['brand_ids']);

        $withoutCategories = $validated;
        unset($withoutCategories['category_ids']);

        $selectedBrandIds = array_map('intval', $validated['brand_ids'] ?? []);
        $selectedCategoryIds = array_map('intval', $validated['category_ids'] ?? []);
        if (! empty($validated['category_id']) && ! in_array((int) $validated['category_id'], $selectedCategoryIds)) {
            $selectedCategoryIds[] = (int) $validated['category_id'];
        }

        $selectedAttributeValueIds = array_map('intval', $validated['attribute_value_ids'] ?? []);
        $withoutAttributes = $validated;
        unset($withoutAttributes['attribute_value_ids'], $withoutAttributes['attribute_inline_filters']);

        return response()->json([
            'brands' => $this->facetService->getBrandFacets($this->buildBaseQuery($withoutBrands), $selectedBrandIds),
            'categories' => $this->facetService->getCategoryFacets($this->buildBaseQuery($withoutCategories), $selectedCategoryIds),
            'attributes' => $this->facetService->getAttributeFacets($this->buildBaseQuery($withoutAttributes), $selectedAttributeValueIds),
        ]);
    }

    /**
     * Ценовые интервалы.
     *
     * Возвращает min, max и бакеты для ценового фильтра.
     */
    public function priceIntervals(ProductFilterRequest $request): JsonResponse
    {
        $this->applyRegionFromRequest($request);
        $validated = $request->validated();
        unset($validated['price_min'], $validated['price_max']);

        $query = $this->buildBaseQuery($validated);
        $intervals = $this->facetService->getPriceIntervals($query);

        return response()->json($intervals);
    }

    /**
     * Построить базовый запрос с фильтрами.
     * Переиспользует ту же логику, что и CatalogApiController.
     */
    private function buildBaseQuery(array $validated): Builder
    {
        $query = Product::query();

        $query->where(function ($q) {
            $q->whereNull('category_id')
                ->orWhereHas('category', fn ($cq) => $cq->where('is_active', true));
        });

        if (! empty($validated['q'])) {
            $query->search($validated['q']);
        }

        if (! empty($validated['category_id'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategory((int) $validated['category_id'], (bool) $descendants);
        }

        if (! empty($validated['category_ids'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategories(array_map('intval', $validated['category_ids']), (bool) $descendants);
        }

        if (! empty($validated['brand_ids'])) {
            $query->inBrands(array_map('intval', $validated['brand_ids']));
        }

        if (! empty($validated['collection_ids'])) {
            $query->inCollections(array_map('intval', $validated['collection_ids']));
        }

        $priceMin = $validated['price_min'] ?? null;
        $priceMax = $validated['price_max'] ?? null;
        if ($priceMin !== null || $priceMax !== null) {
            $query->byPrice(
                $priceMin !== null ? (float) $priceMin : null,
                $priceMax !== null ? (float) $priceMax : null,
            );
        }

        if (! empty($validated['in_stock'])) {
            $query->inStock('instock');
        }

        if (! empty($validated['in_sale'])) {
            $query->inSale(true);
        }

        if (! empty($validated['is_new'])) {
            $query->where('is_new', true);
        }

        if (! empty($validated['is_bestseller'])) {
            $query->where('is_bestseller', true);
        }

        if (! empty($validated['attribute_value_ids'])) {
            $any = (bool) ($validated['attribute_any'] ?? false);
            $query->byAttributes(array_map('intval', $validated['attribute_value_ids']), $any);
        }

        if (! empty($validated['attribute_inline_filters'])) {
            $query->byInlineAttributes($validated['attribute_inline_filters']);
        }

        return $query;
    }

    /**
     * Scout-builder товарного поиска. `$semantic = true` навешивает hybrid-опции.
     *
     * @return \Laravel\Scout\Builder
     */
    private function productSearchBuilder(string $query, bool $semantic)
    {
        $builder = Product::search($query)
            ->query(function ($q) {
                $q->select('products.*');
                $q->with(ProductQueryService::productEagerLoads());
                ProductQueryService::withRegionStockSums($q);
            });

        if ($semantic && $options = HybridSearchOptions::forProducts()) {
            $builder->options($options);
        }

        return $builder;
    }
}
