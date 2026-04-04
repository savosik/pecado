<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Tags\Tag;

/**
 * Просмотр и создание тегов.
 *
 * @tags Теги
 */
class TagController extends Controller
{
    /**
     * Список тегов.
     *
     * @queryParam search string Поиск по названию
     * @queryParam type string Фильтр по типу
     * @queryParam per_page integer Записей на странице. Default: 50
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query();

        if ($search = $request->input('search')) {
            $query->containing($search);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        $query->orderBy('name');

        $perPage = min(max((int) $request->input('per_page', 50), 5), 200);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'type' => $tag->type,
            ]),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Создать тег.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
        ]);

        $tag = Tag::findOrCreate($validated['name'], $validated['type'] ?? null);

        return response()->json([
            'data' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'type' => $tag->type,
            ],
        ], 201);
    }
}
