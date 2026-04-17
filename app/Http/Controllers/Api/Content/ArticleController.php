<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD статей.
 *
 * @tags Статьи
 */
class ArticleController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список статей.
     *
     * Обзоры, гайды и статьи с поиском по заголовку/описанию, фильтрацией и сортировкой.
     *
     * @queryParam search string Поиск по заголовку и описанию
     * @queryParam is_published boolean Фильтр по публикации
     * @queryParam sort_by string Сортировка: id, title, published_at, created_at, updated_at. Default: id
     * @queryParam sort_order string asc или desc. Default: desc
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::query()->withCount('tags');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('detailed_description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['id', 'title', 'published_at', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Article $item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить одну статью.
     */
    public function show(Article $article): JsonResponse
    {
        return response()->json(['data' => $this->format($article)]);
    }

    /**
     * Создать статью.
     *
     * Поддерживает загрузку изображений (list_item, detail_desktop, detail_mobile)
     * через файл (multipart) или URL. Теги передаются массивом строк.
     * Slug генерируется автоматически если не указан.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:articles,slug',
            'short_description' => 'required|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'list_item' => 'nullable|image|max:20480',
            'detail_desktop' => 'nullable|image|max:20480',
            'detail_mobile' => 'nullable|image|max:20480',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);

        $article = Article::create($validated);

        if (! empty($validated['tags'])) {
            $article->attachTags($validated['tags']);
        }

        $this->handleMediaUpload($request, $article, 'list_item', 'list-item');
        $this->handleMediaUpload($request, $article, 'detail_desktop', 'detail-item-desktop');
        $this->handleMediaUpload($request, $article, 'detail_mobile', 'detail-item-mobile');

        return response()->json(['data' => $this->format($article->fresh())], 201);
    }

    /**
     * Обновить статью.
     *
     * Можно передавать только изменённые поля. При загрузке нового изображения старое заменяется.
     * Для обновления тегов передайте массив — старые теги будут заменены.
     */
    public function update(Request $request, Article $article): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:articles,slug,'.$article->id,
            'short_description' => 'sometimes|required|string',
            'detailed_description' => 'sometimes|required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'list_item' => 'nullable|image|max:20480',
            'detail_desktop' => 'nullable|image|max:20480',
            'detail_mobile' => 'nullable|image|max:20480',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
        ]);

        $article->update($validated);

        if (array_key_exists('tags', $validated)) {
            $article->syncTags($validated['tags'] ?? []);
        }

        $this->handleMediaUpload($request, $article, 'list_item', 'list-item', clearFirst: true);
        $this->handleMediaUpload($request, $article, 'detail_desktop', 'detail-item-desktop', clearFirst: true);
        $this->handleMediaUpload($request, $article, 'detail_mobile', 'detail-item-mobile', clearFirst: true);

        return response()->json(['data' => $this->format($article->fresh())]);
    }

    /**
     * Удалить статью.
     */
    public function destroy(Article $article): JsonResponse
    {
        $article->delete();

        return response()->json(null, 204);
    }

    private function format(Article $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'short_description' => $article->short_description,
            'detailed_description' => $article->detailed_description,
            'is_published' => (bool) $article->is_published,
            'published_at' => $article->published_at?->toIso8601String(),
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'tags' => $article->tags->pluck('name')->toArray(),
            'images' => $this->getMediaUrls($article, ['list-item', 'detail-item-desktop', 'detail-item-mobile']),
            'created_at' => $article->created_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];
    }
}
