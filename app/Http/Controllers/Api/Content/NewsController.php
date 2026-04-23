<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD новостей.
 *
 * @tags Новости
 */
class NewsController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список новостей.
     *
     * Поддерживает поиск, сортировку и пагинацию.
     *
     * @queryParam search string Поиск по заголовку, краткому и полному описанию
     * @queryParam sort_by string Поле сортировки (id, title, published_at, created_at). Default: id
     * @queryParam sort_order string Направление (asc, desc). Default: desc
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     * @queryParam is_published boolean Фильтр по статусу публикации
     */
    public function index(Request $request): JsonResponse
    {
        $query = News::query()->withCount('tags');

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

        $data = $paginated->getCollection()->map(fn (News $item) => $this->formatNews($item));

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить одну новость.
     */
    public function show(News $news): JsonResponse
    {
        return response()->json([
            'data' => $this->formatNews($news),
        ]);
    }

    /**
     * Создать новость.
     *
     * Изображения можно загрузить файлом (list_item, detail_desktop, detail_mobile)
     * или указать URL (list_item_url, detail_desktop_url, detail_mobile_url).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:news,slug',
            'short_description' => 'nullable|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            // File upload
            'list_item' => 'nullable|image|max:20480',
            'detail_desktop' => 'nullable|image|max:20480',
            'detail_mobile' => 'nullable|image|max:20480',
            // URL upload
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);

        $news = News::create($validated);

        if (! empty($validated['tags'])) {
            $news->attachTags($validated['tags']);
        }

        $this->handleMediaUpload($request, $news, 'list_item', 'list-item');
        $this->handleMediaUpload($request, $news, 'detail_desktop', 'detail-item-desktop');
        $this->handleMediaUpload($request, $news, 'detail_mobile', 'detail-item-mobile');

        return response()->json([
            'data' => $this->formatNews($news->fresh()),
        ], 201);
    }

    /**
     * Обновить новость.
     */
    public function update(Request $request, News $news): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:news,slug,'.$news->id,
            'short_description' => 'nullable|string',
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

        $news->update($validated);

        if (array_key_exists('tags', $validated)) {
            $news->syncTags($validated['tags'] ?? []);
        }

        $this->handleMediaUpload($request, $news, 'list_item', 'list-item', clearFirst: true);
        $this->handleMediaUpload($request, $news, 'detail_desktop', 'detail-item-desktop', clearFirst: true);
        $this->handleMediaUpload($request, $news, 'detail_mobile', 'detail-item-mobile', clearFirst: true);

        return response()->json([
            'data' => $this->formatNews($news->fresh()),
        ]);
    }

    /**
     * Удалить новость.
     */
    public function destroy(News $news): JsonResponse
    {
        $news->delete();

        return response()->json(null, 204);
    }

    private function formatNews(News $news): array
    {
        return [
            'id' => $news->id,
            'title' => $news->title,
            'slug' => $news->slug,
            'short_description' => $news->short_description,
            'detailed_description' => $news->detailed_description,
            'is_published' => (bool) $news->is_published,
            'published_at' => $news->published_at?->toIso8601String(),
            'meta_title' => $news->meta_title,
            'meta_description' => $news->meta_description,
            'tags' => $news->tags->pluck('name')->toArray(),
            'images' => $this->getMediaUrls($news, ['list-item', 'detail-item-desktop', 'detail-item-mobile']),
            'created_at' => $news->created_at?->toIso8601String(),
            'updated_at' => $news->updated_at?->toIso8601String(),
        ];
    }
}
