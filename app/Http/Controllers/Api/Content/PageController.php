<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD страниц.
 *
 * @tags Страницы
 */
class PageController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список статических страниц.
     *
     * Страницы сайта: «О компании», «Доставка», «Оплата» и др.
     *
     * @queryParam search string Поиск по заголовку
     * @queryParam is_published boolean Фильтр по публикации
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = Page::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['id', 'title', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Page $item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить одну страницу.
     */
    public function show(Page $page): JsonResponse
    {
        return response()->json(['data' => $this->format($page)]);
    }

    /**
     * Создать страницу.
     *
     * Slug генерируется автоматически если не указан. Поддерживает загрузку изображения.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:pages,slug',
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'list_item' => 'nullable|image|max:20480',
            'detail_desktop' => 'nullable|image|max:20480',
            'detail_mobile' => 'nullable|image|max:20480',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);

        $page = Page::create($validated);

        $this->handleMediaUpload($request, $page, 'list_item', 'list-item');
        $this->handleMediaUpload($request, $page, 'detail_desktop', 'detail-item-desktop');
        $this->handleMediaUpload($request, $page, 'detail_mobile', 'detail-item-mobile');

        return response()->json(['data' => $this->format($page->fresh())], 201);
    }

    /**
     * Обновить страницу.
     *
     * Можно передавать только изменённые поля.
     */
    public function update(Request $request, Page $page): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:pages,slug,' . $page->id,
            'content' => 'sometimes|required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'list_item' => 'nullable|image|max:20480',
            'detail_desktop' => 'nullable|image|max:20480',
            'detail_mobile' => 'nullable|image|max:20480',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
        ]);

        $page->update($validated);

        $this->handleMediaUpload($request, $page, 'list_item', 'list-item', clearFirst: true);
        $this->handleMediaUpload($request, $page, 'detail_desktop', 'detail-item-desktop', clearFirst: true);
        $this->handleMediaUpload($request, $page, 'detail_mobile', 'detail-item-mobile', clearFirst: true);

        return response()->json(['data' => $this->format($page->fresh())]);
    }

    /**
     * Удалить страницу.
     */
    public function destroy(Page $page): JsonResponse
    {
        $page->delete();
        return response()->json(null, 204);
    }

    private function format(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'content' => $page->content,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'images' => $this->getMediaUrls($page, ['list-item', 'detail-item-desktop', 'detail-item-mobile']),
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}
