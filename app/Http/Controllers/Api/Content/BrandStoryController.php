<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\BrandStory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD статей о брендах.
 *
 * @tags Статьи о брендах
 */
class BrandStoryController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список историй брендов.
     *
     * Контентные истории о брендах. Можно фильтровать по бренду.
     *
     * @queryParam search string Поиск по заголовку
     * @queryParam brand_id integer Фильтр по бренду
     * @queryParam is_published boolean Фильтр по публикации
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = BrandStory::query()->with('brand')->withCount('tags');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        if ($brandId = $request->input('brand_id')) {
            $query->where('brand_id', $brandId);
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['id', 'title', 'published_at', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (BrandStory $item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить одну историю бренда.
     *
     * Включает данные бренда.
     */
    public function show(BrandStory $brandStory): JsonResponse
    {
        $brandStory->load('brand');

        return response()->json(['data' => $this->format($brandStory)]);
    }

    /**
     * Создать историю бренда.
     *
     * Привязывается к существующему бренду через brand_id.
     * Поддерживает загрузку изображения через файл или URL.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:brand_stories,slug',
            'short_description' => 'required|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'brand_id' => 'required|exists:brands,id',
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

        $brandStory = BrandStory::create($validated);

        if (! empty($validated['tags'])) {
            $brandStory->attachTags($validated['tags']);
        }

        $this->handleMediaUpload($request, $brandStory, 'list_item', 'list-item');
        $this->handleMediaUpload($request, $brandStory, 'detail_desktop', 'detail-item-desktop');
        $this->handleMediaUpload($request, $brandStory, 'detail_mobile', 'detail-item-mobile');

        return response()->json(['data' => $this->format($brandStory->fresh()->load('brand'))], 201);
    }

    /**
     * Обновить историю бренда.
     *
     * Можно передавать только изменённые поля.
     */
    public function update(Request $request, BrandStory $brandStory): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:brand_stories,slug,'.$brandStory->id,
            'short_description' => 'sometimes|required|string',
            'detailed_description' => 'sometimes|required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'brand_id' => 'sometimes|required|exists:brands,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'list_item' => 'nullable|image|max:20480',
            'detail_desktop' => 'nullable|image|max:20480',
            'detail_mobile' => 'nullable|image|max:20480',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
        ]);

        $brandStory->update($validated);

        if (array_key_exists('tags', $validated)) {
            $brandStory->syncTags($validated['tags'] ?? []);
        }

        $this->handleMediaUpload($request, $brandStory, 'list_item', 'list-item', clearFirst: true);
        $this->handleMediaUpload($request, $brandStory, 'detail_desktop', 'detail-item-desktop', clearFirst: true);
        $this->handleMediaUpload($request, $brandStory, 'detail_mobile', 'detail-item-mobile', clearFirst: true);

        return response()->json(['data' => $this->format($brandStory->fresh()->load('brand'))]);
    }

    /**
     * Удалить историю бренда.
     */
    public function destroy(BrandStory $brandStory): JsonResponse
    {
        $brandStory->delete();

        return response()->json(null, 204);
    }

    private function format(BrandStory $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'slug' => $item->slug,
            'short_description' => $item->short_description,
            'detailed_description' => $item->detailed_description,
            'is_published' => (bool) $item->is_published,
            'published_at' => $item->published_at?->toIso8601String(),
            'meta_title' => $item->meta_title,
            'meta_description' => $item->meta_description,
            'brand' => $item->brand ? [
                'id' => $item->brand->id,
                'name' => $item->brand->name,
                'slug' => $item->brand->slug,
            ] : null,
            'tags' => $item->tags->pluck('name')->toArray(),
            'images' => $this->getMediaUrls($item, ['list-item', 'detail-item-desktop', 'detail-item-mobile']),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
