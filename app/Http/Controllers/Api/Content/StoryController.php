<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD сториз.
 *
 * @tags Сториз
 */
class StoryController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список сториз.
     *
     * Instagram-style сториз с кол-вом слайдов и обложкой.
     *
     * @queryParam search string Поиск по названию
     * @queryParam is_active boolean Фильтр по активности
     * @queryParam is_published boolean Фильтр по публикации
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = Story::query()->withCount('slides');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $allowedSorts = ['id', 'name', 'sort_order', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Story $item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить сторис со слайдами.
     *
     * Возвращает полные данные сториса включая все слайды по порядку.
     */
    public function show(Story $story): JsonResponse
    {
        $story->load(['slides' => fn ($q) => $q->orderBy('sort_order')]);

        $data = $this->format($story);
        $data['slides'] = $story->slides->map(function ($slide) {
            return [
                'id' => $slide->id,
                'title' => $slide->title,
                'content' => $slide->content,
                'button_text' => $slide->button_text,
                'button_url' => $slide->button_url,
                'linkable_type' => $slide->linkable_type,
                'linkable_id' => $slide->linkable_id,
                'duration' => $slide->duration,
                'sort_order' => $slide->sort_order,
                'media_url' => $slide->getFirstMediaUrl('default') ?: null,
                'created_at' => $slide->created_at?->toIso8601String(),
                'updated_at' => $slide->updated_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Создать сторис.
     *
     * После создания добавьте слайды через POST /stories/{id}/slides.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:stories,slug',
            'is_active' => 'boolean',
            'is_published' => 'boolean',
            'show_name' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        $story = Story::create($validated);

        return response()->json(['data' => $this->format($story)], 201);
    }

    /**
     * Обновить сторис.
     */
    public function update(Request $request, Story $story): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|unique:stories,slug,'.$story->id,
            'is_active' => 'boolean',
            'is_published' => 'boolean',
            'show_name' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $story->update($validated);

        return response()->json(['data' => $this->format($story->fresh())]);
    }

    /**
     * Удалить сторис.
     *
     * Каскадно удаляет все слайды.
     */
    public function destroy(Story $story): JsonResponse
    {
        $story->delete();

        return response()->json(null, 204);
    }

    private function format(Story $story): array
    {
        return [
            'id' => $story->id,
            'name' => $story->name,
            'slug' => $story->slug,
            'is_active' => (bool) $story->is_active,
            'is_published' => (bool) $story->is_published,
            'show_name' => (bool) $story->show_name,
            'sort_order' => $story->sort_order,
            'slides_count' => $story->slides_count ?? $story->slides()->count(),
            'created_at' => $story->created_at?->toIso8601String(),
            'updated_at' => $story->updated_at?->toIso8601String(),
        ];
    }
}
