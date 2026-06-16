<?php

namespace App\Http\Controllers\User;

use App\Helpers\ContentHelper;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use App\Models\SearchHistory;
use App\Services\Product\ProductQueryService;
use App\Services\Search\ExactProductMatcher;
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
    public function __construct(private readonly ExactProductMatcher $exactMatcher) {}

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
            // Удаляем предыдущие дубликаты, чтобы запрос не повторялся в списке
            if ($request->user() && $page === 1) {
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
        }

        $responseData = [
            'query' => $query,
            'type' => $type,
            'availability' => $availability,
            'results' => $results,
            'productsMeta' => $productsMeta,
        ];

        if ($request->wantsJson()) {
            return response()->json($responseData);
        }

        return Inertia::render('User/Search/Index', $responseData);
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

        $exactMatch = $this->exactMatcher->match($validated['q']);

        try {
            $searchBuilder = Product::search($validated['q'])
                ->query(fn ($q) => $q->with(['brand', 'media']));

            // Гибридный поиск (полнотекст + семантика)
            if ($hybridOptions = $this->getHybridSearchOptions()) {
                $searchBuilder->options($hybridOptions);
            }

            $products = $searchBuilder->take(8)->get();
        } catch (\Throwable) {
            $products = collect();
        }

        if ($exactMatch !== null) {
            $products = $products->reject(fn (Product $p) => $p->id === $exactMatch->id)->values();
            $products->prepend($exactMatch);
            $products = $products->take(8);
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
            $exactMatch = $page === 1 ? $this->exactMatcher->match($query) : null;

            try {
                $searchBuilder = Product::search($query)
                    ->query(function ($q) {
                        $q->select('products.*');
                        $q->with(ProductQueryService::productEagerLoads());
                        ProductQueryService::withRegionStockSums($q);
                    });

                // Гибридный поиск (полнотекст + семантика)
                if ($hybridOptions = $this->getHybridSearchOptions()) {
                    $searchBuilder->options($hybridOptions);
                }

                $paginated = $searchBuilder->paginate($perPage, 'page', $page);

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

                // Пин точного матча первым; если Scout его не вернул — total +1.
                $exactWasInScout = false;
                if ($exactMatch !== null) {
                    $exactWasInScout = $products->contains(fn (Product $p) => $p->id === $exactMatch->id);
                    $products = $products->reject(fn (Product $p) => $p->id === $exactMatch->id)->values();
                    $products->prepend($exactMatch);
                }

                // Точное вхождение запроса (case-insensitive) в name/sku/code/barcode/brand
                // хотя бы одного из товаров? Если нет — Scout вернул только фаззи-соседей,
                // фронт покажет «Точного совпадения не найдено — похожие товары».
                $hasExact = $exactMatch !== null || $this->exactMatcher->hasLiteralMatch($products, $query);

                // Преобразование через ProductQueryService (полный формат, как в каталоге)
                $productArray = $products
                    ->map(fn (Product $product) => ProductQueryService::productToArray($product))
                    ->values()
                    ->toArray();

                // Обогащение скидками и конвертация валют
                $productArray = ProductQueryService::enrichProductsWithDiscounts($productArray);
                $productArray = ProductQueryService::convertProductsPrices($productArray);

                $results['products'] = $productArray;

                // Мета-данные пагинации
                $total = $paginated->total();
                if ($exactMatch !== null && ! $exactWasInScout) {
                    $total += 1;
                }

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
                // Scout упал — отдаём хотя бы точный матч, если он есть.
                if ($exactMatch !== null) {
                    $exactArray = [ProductQueryService::productToArray($exactMatch)];
                    $exactArray = ProductQueryService::enrichProductsWithDiscounts($exactArray);
                    $exactArray = ProductQueryService::convertProductsPrices($exactArray);
                    $results['products'] = $exactArray;
                    $results['_products_meta'] = [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 1,
                        'from' => 1,
                        'to' => 1,
                        'no_exact_match' => false,
                    ];
                } else {
                    $results['products'] = [];
                    $results['_products_meta'] = null;
                }
            }
        }

        // ── Категории ─────────────────────────────────────────────
        if ($searchAll || $type === 'categories') {
            $categoryLimit = $limit ?? 5;

            try {
                $results['categories'] = Category::search($query)
                    ->take($categoryLimit)
                    ->get()
                    ->filter(fn (Category $category) => $category->is_active)
                    ->map(fn (Category $category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ])->values()->toArray();
            } catch (\Throwable) {
                $results['categories'] = [];
            }
        }

        // ── Бренды ────────────────────────────────────────────────
        if ($searchAll || $type === 'brands') {
            $brandLimit = $limit ?? 5;

            try {
                $results['brands'] = Brand::search($query)
                    ->take($brandLimit)
                    ->get()
                    ->map(fn (Brand $brand) => [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                    ])->toArray();
            } catch (\Throwable) {
                $results['brands'] = [];
            }
        }

        // ── Статьи и Новости ──────────────────────────────────────
        if ($searchAll || $type === 'articles') {
            $contentLimit = $limit ?? 5;

            try {
                $articles = Article::search($query)
                    ->query(fn ($q) => $q->published())
                    ->take($contentLimit)
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
                    ])->toArray();
            } catch (\Throwable) {
                $articles = [];
            }

            try {
                $news = News::search($query)
                    ->query(fn ($q) => $q->published())
                    ->take($contentLimit)
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
                    ])->toArray();
            } catch (\Throwable) {
                $news = [];
            }

            $results['articles'] = $articles;
            $results['news'] = $news;
        }

        return $results;
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
     * Получить опции гибридного поиска для Meilisearch.
     */
    private function getHybridSearchOptions(): ?array
    {
        if (! config('search.hybrid.enabled')) {
            return null;
        }

        return [
            'hybrid' => [
                'embedder' => config('search.hybrid.embedder'),
                'semanticRatio' => config('search.hybrid.semantic_ratio'),
            ],
        ];
    }
}
