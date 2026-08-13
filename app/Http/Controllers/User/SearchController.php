<?php

namespace App\Http\Controllers\User;

use App\Helpers\ContentHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\SearchFilterRequest;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use App\Models\SearchHistory;
use App\Services\Product\ProductQueryService;
use App\Services\Search\ExactProductMatcher;
use App\Services\Search\ProductSearchResolver;
use App\Support\Impersonation;
use App\Support\Search\HybridSearchOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Контроллер поиска.
 *
 * Основной поиск по всем сущностям (товары, категории, бренды, статьи, новости)
 * и быстрые подсказки (только товары).
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly ExactProductMatcher $exactMatcher,
        private readonly ProductSearchResolver $resolver,
    ) {}

    /**
     * Основной поиск по всем сущностям.
     *
     * GET /search
     *
     * При Accept: application/json → JSON-ответ.
     * При обычном запросе → Inertia User/Search/Index.
     * Для авторизованных пользователей сохраняет запрос в историю.
     */
    public function index(Request $request): JsonResponse|InertiaResponse
    {
        // Страница /search (не JSON) собирается иначе: товары фронт грузит сам через
        // /api/search/products — с фильтрами, фасетами и сортировками каталога.
        if (! $request->wantsJson()) {
            return $this->renderPage($request);
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:255'],
            'type' => ['sometimes', 'string', 'in:all,products,categories,brands,articles'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'availability' => ['sometimes', 'string', 'in:all,in_stock,in_stock_preorder'],
            'include_unavailable' => ['sometimes', 'boolean'],
        ], [
            'q.min' => 'Минимум 2 символа для поиска.',
            'q.max' => 'Запрос не может быть длиннее 255 символов.',
        ]);

        $query = $validated['q'] ?? null;
        $type = $validated['type'] ?? 'all';
        $limit = $validated['limit'] ?? null;
        $page = (int) ($validated['page'] ?? 1);

        // Режим фильтрации по наличию: all | in_stock | in_stock_preorder.
        // По умолчанию показываем все товары. Сохраняем обратную совместимость
        // со старым булевым параметром include_unavailable.
        $availability = $validated['availability'] ?? null;
        if ($availability === null) {
            $includeUnavailable = (bool) ($validated['include_unavailable'] ?? true);
            $availability = $includeUnavailable ? 'all' : 'in_stock_preorder';
        }

        // Без запроса — пустые результаты (страница /search без параметров)
        $results = [];
        $productsMeta = null;
        $totalCount = 0;

        if ($query) {
            $results = $this->performSearch($query, $type, $limit, $page, $availability);

            // Извлекаем мета-данные пагинации товаров
            if (isset($results['_products_meta'])) {
                $productsMeta = $results['_products_meta'];
                unset($results['_products_meta']);
            }

            $totalCount = collect($results)->flatten(1)->count();
            if ($productsMeta) {
                $totalCount += $productsMeta['total'] - count($results['products'] ?? []);
            }

            // Сохранение запроса в историю для авторизованных пользователей
            if ($page === 1) {
                $this->rememberQuery($request, $query, $totalCount);
            }
        }

        $responseData = [
            'query' => $query,
            'type' => $type,
            'availability' => $availability,
            'results' => $results,
            'productsMeta' => $productsMeta,
        ];

        return response()->json($responseData);
    }

    /**
     * Страница результатов поиска.
     *
     * Товары отдаёт не она, а `/api/search/products` — здесь только запрос,
     * прочие сущности (категории, бренды, статьи, новости) и стартовые фильтры.
     */
    private function renderPage(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:255'],
            'availability' => ['sometimes', 'string', 'in:all,in_stock,in_stock_preorder'],
        ], [
            'q.min' => 'Минимум 2 символа для поиска.',
            'q.max' => 'Запрос не может быть длиннее 255 символов.',
        ]);

        $query = $validated['q'] ?? null;

        $results = [
            'categories' => [],
            'brands' => [],
            'articles' => [],
            'news' => [],
        ];
        $productsTotal = 0;

        if ($query) {
            $results = $this->searchEntities($query, 8);
            $productsTotal = count($this->resolver->resolve($query)['ids']);

            $this->rememberQuery(
                $request,
                $query,
                $productsTotal + collect($results)->flatten(1)->count(),
            );
        }

        $initialFilters = ['q' => $query];

        // Обратная совместимость со старыми ссылками /search?availability=…:
        // теперь наличие — обычный фильтр каталога in_stock_mode, а режим «все»
        // (включая товары не в наличии) остался поведением по умолчанию.
        $legacyAvailability = match ($validated['availability'] ?? null) {
            'in_stock' => 'instock',
            'in_stock_preorder' => 'available',
            default => null,
        };
        if ($legacyAvailability !== null) {
            $initialFilters['in_stock_mode'] = $legacyAvailability;
        }

        return Inertia::render('User/Search/Index', [
            'query' => $query,
            'results' => $results,
            'initialFilters' => $initialFilters,
            'sortOptions' => SearchFilterRequest::sortOptions(),
            'appName' => config('app.name'),
        ]);
    }

    /**
     * Записать запрос в историю поиска.
     *
     * Предыдущие дубликаты удаляем, чтобы запрос не повторялся в подсказках.
     * В режиме просмотра от имени клиента историю не пишем: искал менеджер,
     * а клиент увидел бы чужие запросы в своих подсказках.
     */
    private function rememberQuery(Request $request, string $query, int $totalCount): void
    {
        if (! $request->user() || Impersonation::active()) {
            return;
        }

        SearchHistory::where('user_id', $request->user()->id)
            ->where('query', $query)
            ->delete();

        SearchHistory::create([
            'user_id' => $request->user()->id,
            'query' => $query,
            'results_count' => $totalCount,
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Быстрые подсказки — только товары.
     *
     * GET /api/search/suggestions
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ], [
            'q.required' => 'Введите поисковый запрос.',
            'q.min' => 'Минимум 2 символа для поиска.',
        ]);

        // Точное совпадение по артикулу / коду 1С / штрихкоду → отдаём ТОЛЬКО
        // товары с идентичным кодом (обычно один, изредка несколько). Meilisearch
        // не трогаем, чтобы не подмешивать фаззи-соседей.
        $exactMatches = $this->exactMatcher->matchAll($validated['q']);
        if ($exactMatches->isNotEmpty()) {
            return response()->json(
                $exactMatches->map(fn (Product $product) => $this->formatProductCompact($product))->values()
            );
        }

        try {
            // Keyword-first: сначала чистый полнотекст; семантику подмешиваем
            // только если keyword дал мало результатов (опечатки/синонимы).
            $products = $this->suggestionResults($validated['q'], semantic: false);

            if (HybridSearchOptions::shouldFallback($products->count())) {
                $products = $this->suggestionResults($validated['q'], semantic: true);
            }
        } catch (\Throwable) {
            $products = collect();
        }

        $products = $products->map(fn (Product $product) => $this->formatProductCompact($product));

        return response()->json($products);
    }

    /**
     * История поиска текущего пользователя.
     *
     * GET /api/search/history (auth)
     */
    public function history(Request $request): JsonResponse
    {
        $history = SearchHistory::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'query', 'results_count', 'created_at']);

        return response()->json($history);
    }

    /**
     * Очистить всю историю поиска текущего пользователя.
     *
     * DELETE /api/search/history (auth)
     */
    public function clearHistory(Request $request): JsonResponse
    {
        SearchHistory::where('user_id', $request->user()->id)->delete();

        return response()->json(null, 204);
    }

    /**
     * Удалить одну запись из истории поиска.
     *
     * DELETE /api/search/history/{history} (auth)
     */
    public function deleteHistory(Request $request, SearchHistory $history): JsonResponse
    {
        if ((int) $history->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещён.');
        }

        $history->delete();

        return response()->json(null, 204);
    }

    // ──────────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────────

    /**
     * Выполнить поиск по указанным типам.
     *
     * @return array<string, array>
     */
    private function performSearch(string $query, string $type, ?int $limit, int $page, string $availability): array
    {
        $results = [];
        $searchAll = $type === 'all';

        // ── Товары ────────────────────────────────────────────────
        if ($searchAll || $type === 'products') {
            $perPage = $limit ?? 20;

            // Fast-path: точное совпадение по sku/code/barcode (только на первой странице).
            // Если запрос 100% совпал с артикулом / кодом 1С / штрихкодом — отдаём
            // ТОЛЬКО товары с идентичным кодом (обычно один, изредка несколько) и не
            // обращаемся к Meilisearch (иначе он подмешает фаззи-соседей). Прочие
            // сущности (категории, бренды, статьи) тоже пропускаем — искали код.
            $exactMatches = $page === 1 ? $this->exactMatcher->matchAll($query) : collect();
            if ($exactMatches->isNotEmpty()) {
                $exactArray = $exactMatches
                    ->map(fn (Product $product) => ProductQueryService::productToArray($product))
                    ->values()
                    ->toArray();
                $exactArray = ProductQueryService::enrichProductsWithDiscounts($exactArray);
                $exactArray = ProductQueryService::convertProductsPrices($exactArray);
                $exactArray = ProductQueryService::enrichProductsWithPromotions($exactArray);

                $count = count($exactArray);
                $results['products'] = $exactArray;
                $results['_products_meta'] = [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => $count,
                    'from' => 1,
                    'to' => $count,
                    'no_exact_match' => false,
                ];

                return $results;
            }

            try {
                // Keyword-first: сначала чистый полнотекстовый поиск, чтобы точные
                // совпадения не тонули среди семантических «соседей». Семантику
                // (hybrid) подмешиваем повтором запроса только при слабой выдаче.
                $paginated = $this->productSearchBuilder($query, semantic: false)
                    ->paginate($perPage, 'page', $page);

                if (HybridSearchOptions::shouldFallback($paginated->total())) {
                    $paginated = $this->productSearchBuilder($query, semantic: true)
                        ->paginate($perPage, 'page', $page);
                }

                $products = $paginated->getCollection();

                // Фильтрация по наличию.
                // - in_stock: только товары с остатком на основных складах региона.
                // - in_stock_preorder: в наличии ИЛИ доступные под предзаказ.
                // - all: без фильтрации.
                if ($availability === 'in_stock') {
                    $products = $products->filter(
                        fn (Product $product) => ($product->primary_stock ?? 0) > 0
                    )->values();
                } elseif ($availability === 'in_stock_preorder') {
                    $products = $products->filter(
                        fn (Product $product) => ($product->primary_stock ?? 0) > 0
                            || ($product->preorder_stock ?? 0) > 0
                    )->values();
                }

                // Стабильная пересортировка: товары в наличии (primary_stock > 0) выше
                // товаров только под предзаказ. Порядок Meilisearch внутри каждой группы
                // сохраняется (partition стабилен), поэтому релевантность не ломается.
                [$inStockProducts, $preorderProducts] = $products->partition(
                    fn (Product $product) => ($product->primary_stock ?? 0) > 0
                );
                $products = $inStockProducts->concat($preorderProducts)->values();

                // Сюда попадаем только когда точного матча по коду НЕ было
                // (иначе выше отдали единственный товар и вышли). Проверяем точное
                // вхождение запроса (case-insensitive) в name/sku/code/barcode/brand
                // хотя бы одного из товаров? Если нет — Scout вернул только фаззи-соседей,
                // фронт покажет «Точного совпадения не найдено — похожие товары».
                $hasExact = $this->exactMatcher->hasLiteralMatch($products, $query);

                // Преобразование через ProductQueryService (полный формат, как в каталоге)
                $productArray = $products
                    ->map(fn (Product $product) => ProductQueryService::productToArray($product))
                    ->values()
                    ->toArray();

                // Обогащение скидками и конвертация валют
                $productArray = ProductQueryService::enrichProductsWithDiscounts($productArray);
                $productArray = ProductQueryService::convertProductsPrices($productArray);
                $productArray = ProductQueryService::enrichProductsWithPromotions($productArray);

                $results['products'] = $productArray;

                // Мета-данные пагинации
                $total = $paginated->total();

                $from = $paginated->firstItem();
                $to = $paginated->lastItem();

                // Meilisearch отдаёт estimatedTotalHits (оценку), которая может быть
                // меньше фактически возвращённых хитов. В таком случае гарантируем
                // total >= to и last_page, согласованный с total, чтобы не было
                // «Показано 1–92 из 89».
                if ($to !== null && $to > $total) {
                    $total = $to;
                }

                $paginatorPerPage = $paginated->perPage();
                $lastPage = $paginatorPerPage > 0
                    ? max(1, (int) ceil($total / $paginatorPerPage))
                    : $paginated->lastPage();

                $results['_products_meta'] = [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $lastPage,
                    'per_page' => $paginatorPerPage,
                    'total' => $total,
                    'from' => $from,
                    'to' => $to,
                    'no_exact_match' => $total > 0 && ! $hasExact,
                ];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SearchController: Scout запрос упал', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                // Точный матч по коду отрабатывает выше отдельной веткой, сюда он
                // не доходит — при падении Scout просто отдаём пустой результат.
                $results['products'] = [];
                $results['_products_meta'] = null;
            }
        }

        // ── Категории ─────────────────────────────────────────────
        if ($searchAll || $type === 'categories') {
            $results['categories'] = $this->searchCategories($query, $limit ?? 5);
        }

        // ── Бренды ────────────────────────────────────────────────
        if ($searchAll || $type === 'brands') {
            $results['brands'] = $this->searchBrands($query, $limit ?? 5);
        }

        // ── Статьи и Новости ──────────────────────────────────────
        if ($searchAll || $type === 'articles') {
            $results['articles'] = $this->searchArticles($query, $limit ?? 5);
            $results['news'] = $this->searchNews($query, $limit ?? 5);
        }

        return $results;
    }

    /**
     * Прочие сущности (не товары) по запросу — для страницы результатов.
     *
     * @return array{categories: array, brands: array, articles: array, news: array}
     */
    private function searchEntities(string $query, int $limit): array
    {
        return [
            'categories' => $this->searchCategories($query, $limit),
            'brands' => $this->searchBrands($query, $limit),
            'articles' => $this->searchArticles($query, $limit),
            'news' => $this->searchNews($query, $limit),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchCategories(string $query, int $limit): array
    {
        try {
            return Category::search($query)
                ->take($limit)
                ->get()
                ->filter(fn (Category $category) => $category->is_active)
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchBrands(string $query, int $limit): array
    {
        try {
            return Brand::search($query)
                ->take($limit)
                ->get()
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                ])->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchArticles(string $query, int $limit): array
    {
        try {
            return Article::search($query)
                ->query(fn ($q) => $q->published())
                ->take($limit)
                ->get()
                ->map(fn (Article $article) => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'slug' => $article->slug,
                    'excerpt' => $article->short_description,
                    'image_url' => $article->getFirstMediaUrl('cover', 'thumb') ?: $article->getFirstMediaUrl('cover'),
                    'published_at' => $article->published_at?->format('d.m.Y'),
                    'type' => 'article',
                    'type_label' => 'Статья',
                ])->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchNews(string $query, int $limit): array
    {
        try {
            return News::search($query)
                ->query(fn ($q) => $q->published())
                ->take($limit)
                ->get()
                ->map(fn (News $newsItem) => [
                    'id' => $newsItem->id,
                    'title' => $newsItem->title,
                    'slug' => $newsItem->slug,
                    'excerpt' => $newsItem->short_description
                        ?: ContentHelper::extractText($newsItem->detailed_description, 150),
                    'published_at' => $newsItem->published_at?->format('d.m.Y'),
                    'type' => 'news',
                    'type_label' => 'Новость',
                ])->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Компактный формат товара для подсказок.
     */
    private function formatProductCompact(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => (float) $product->base_price,
            'image_url' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
        ];
    }

    /**
     * Scout-builder товарного поиска. `$semantic = true` навешивает hybrid-опции
     * (keyword + семантика); иначе — чистый полнотекстовый поиск.
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

    /**
     * Товарные подсказки (до 8 шт) для дропдауна. `$semantic` включает hybrid.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function suggestionResults(string $query, bool $semantic): \Illuminate\Support\Collection
    {
        $builder = Product::search($query)
            ->query(fn ($q) => $q->with(['brand', 'media']));

        if ($semantic && $options = HybridSearchOptions::forProducts()) {
            $builder->options($options);
        }

        return $builder->take(8)->get();
    }
}
