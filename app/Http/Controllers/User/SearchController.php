<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use App\Models\SearchHistory;
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
            'q'                    => ['nullable', 'string', 'min:2', 'max:255'],
            'type'                 => ['sometimes', 'string', 'in:all,products,categories,brands,articles'],
            'limit'                => ['sometimes', 'integer', 'min:1', 'max:50'],
            'include_unavailable'  => ['sometimes', 'boolean'],
        ], [
            'q.min'  => 'Минимум 2 символа для поиска.',
            'q.max'  => 'Запрос не может быть длиннее 255 символов.',
        ]);

        $query              = $validated['q'] ?? null;
        $type               = $validated['type'] ?? 'all';
        $limit              = $validated['limit'] ?? null;
        $includeUnavailable = (bool) ($validated['include_unavailable'] ?? false);

        // Без запроса — пустые результаты (страница /search без параметров)
        $results    = [];
        $totalCount = 0;

        if ($query) {
            $results = $this->performSearch($query, $type, $limit, $includeUnavailable);
            $totalCount = collect($results)->flatten(1)->count();

            // Сохранение запроса в историю для авторизованных пользователей
            if ($request->user()) {
                SearchHistory::create([
                    'user_id'       => $request->user()->id,
                    'query'         => $query,
                    'results_count' => $totalCount,
                    'ip_address'    => $request->ip(),
                ]);
            }
        }

        $responseData = [
            'query'   => $query,
            'type'    => $type,
            'results' => $results,
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
            'q.min'      => 'Минимум 2 символа для поиска.',
        ]);

        $products = Product::search($validated['q'])
            ->query(fn ($q) => $q->with(['brand', 'media']))
            ->take(8)
            ->get()
            ->map(fn (Product $product) => $this->formatProductCompact($product));

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
    private function performSearch(string $query, string $type, ?int $limit, bool $includeUnavailable): array
    {
        $results = [];
        $searchAll = $type === 'all';

        // ── Товары ────────────────────────────────────────────────
        if ($searchAll || $type === 'products') {
            $productLimit = $limit ?? 10;

            // Запрашиваем с запасом ×3, чтобы после фильтрации по наличию
            // осталось достаточно результатов
            $fetchLimit = $includeUnavailable ? $productLimit : $productLimit * 3;

            $products = Product::search($query)
                ->query(fn ($q) => $q->with(['brand', 'category', 'media', 'warehouses']))
                ->take($fetchLimit)
                ->get();

            // Фильтрация по наличию (если не include_unavailable)
            if (! $includeUnavailable) {
                $products = $products->filter(function (Product $product) {
                    return $product->warehouses->sum('pivot.quantity') > 0;
                })->values();
            }

            $results['products'] = $products->take($productLimit)->map(fn (Product $product) => [
                'id'                 => $product->id,
                'name'               => $product->name,
                'slug'               => $product->slug,
                'price'              => (float) $product->base_price,
                'available_quantity' => $product->warehouses->sum('pivot.quantity'),
                // TODO: реализовать логику предзаказа, когда добавятся типы складов
                'is_preorder'        => false,
                'image_url'          => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
                'brand'              => $product->brand ? [
                    'id'   => $product->brand->id,
                    'name' => $product->brand->name,
                    'slug' => $product->brand->slug,
                ] : null,
                'category'           => $product->category ? [
                    'id'   => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
            ])->toArray();
        }

        // ── Категории ─────────────────────────────────────────────
        if ($searchAll || $type === 'categories') {
            $categoryLimit = $limit ?? 5;

            $results['categories'] = Category::search($query)
                ->take($categoryLimit)
                ->get()
                ->map(fn (Category $category) => [
                    'id'   => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])->toArray();
        }

        // ── Бренды ────────────────────────────────────────────────
        if ($searchAll || $type === 'brands') {
            $brandLimit = $limit ?? 5;

            $results['brands'] = Brand::search($query)
                ->take($brandLimit)
                ->get()
                ->map(fn (Brand $brand) => [
                    'id'   => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                ])->toArray();
        }

        // ── Статьи и Новости ──────────────────────────────────────
        if ($searchAll || $type === 'articles') {
            $contentLimit = $limit ?? 5;

            $articles = Article::search($query)
                ->query(fn ($q) => $q->published())
                ->take($contentLimit)
                ->get()
                ->map(fn (Article $article) => [
                    'id'           => $article->id,
                    'title'        => $article->title,
                    'slug'         => $article->slug,
                    'excerpt'      => $article->short_description,
                    'image_url'    => $article->getFirstMediaUrl('cover', 'thumb') ?: $article->getFirstMediaUrl('cover'),
                    'published_at' => $article->published_at?->format('d.m.Y'),
                    'type'         => 'article',
                    'type_label'   => 'Статья',
                ])->toArray();

            $news = News::search($query)
                ->query(fn ($q) => $q->published())
                ->take($contentLimit)
                ->get()
                ->map(fn (News $newsItem) => [
                    'id'           => $newsItem->id,
                    'title'        => $newsItem->title,
                    'slug'         => $newsItem->slug,
                    'excerpt'      => $this->truncateText($newsItem->detailed_description),
                    'published_at' => $newsItem->published_at?->format('d.m.Y'),
                    'type'         => 'news',
                    'type_label'   => 'Новость',
                ])->toArray();

            $results['articles'] = $articles;
            $results['news']     = $news;
        }

        return $results;
    }

    /**
     * Компактный формат товара для подсказок.
     */
    private function formatProductCompact(Product $product): array
    {
        return [
            'id'        => $product->id,
            'name'      => $product->name,
            'slug'      => $product->slug,
            'price'     => (float) $product->base_price,
            'image_url' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
        ];
    }

    /**
     * Обрезать HTML-текст до указанной длины с многоточием.
     */
    private function truncateText(?string $html, int $length = 150): ?string
    {
        if (! $html) {
            return null;
        }

        $text = strip_tags($html);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }
}
