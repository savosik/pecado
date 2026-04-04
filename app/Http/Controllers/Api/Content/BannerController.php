<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD баннеров.
 *
 * @tags Баннеры
 */
class BannerController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список баннеров.
     *
     * Баннеры главной страницы с поиском и фильтрацией.
     *
     * @queryParam search string Поиск по заголовку
     * @queryParam is_active boolean Фильтр по активности
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = Banner::query();

        if ($search = $request->input('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $allowedSorts = ['id', 'title', 'sort_order', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Banner $item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить один баннер.
     */
    public function show(Banner $banner): JsonResponse
    {
        return response()->json(['data' => $this->format($banner)]);
    }

    /**
     * Создать баннер.
     *
     * Поддерживает загрузку desktop/mobile изображений через файл (multipart) или URL.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'desktop_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
            'mobile_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
            'desktop_image_url' => 'nullable|url',
            'mobile_image_url' => 'nullable|url',
        ]);

        $banner = Banner::create($validated);

        $this->handleMediaUpload($request, $banner, 'desktop_image', 'desktop');
        $this->handleMediaUpload($request, $banner, 'mobile_image', 'mobile');

        return response()->json(['data' => $this->format($banner->fresh())], 201);
    }

    /**
     * Обновить баннер.
     *
     * Можно передавать только изменённые поля. При отправке нового изображения старое заменяется.
     */
    public function update(Request $request, Banner $banner): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'desktop_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
            'mobile_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
            'desktop_image_url' => 'nullable|url',
            'mobile_image_url' => 'nullable|url',
        ]);

        $banner->update($validated);

        $this->handleMediaUpload($request, $banner, 'desktop_image', 'desktop', clearFirst: true);
        $this->handleMediaUpload($request, $banner, 'mobile_image', 'mobile', clearFirst: true);

        return response()->json(['data' => $this->format($banner->fresh())]);
    }

    /**
     * Удалить баннер.
     */
    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();
        return response()->json(null, 204);
    }

    private function format(Banner $banner): array
    {
        return [
            'id' => $banner->id,
            'title' => $banner->title,
            'linkable_type' => $banner->linkable_type,
            'linkable_id' => $banner->linkable_id,
            'is_active' => (bool) $banner->is_active,
            'sort_order' => $banner->sort_order,
            'images' => [
                'desktop' => $banner->getFirstMediaUrl('desktop') ?: null,
                'mobile' => $banner->getFirstMediaUrl('mobile') ?: null,
            ],
            'created_at' => $banner->created_at?->toIso8601String(),
            'updated_at' => $banner->updated_at?->toIso8601String(),
        ];
    }
}
