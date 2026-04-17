<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD FAQ.
 *
 * @tags FAQ
 */
class FaqController extends Controller
{
    /**
     * Список FAQ.
     *
     * Вопросы и ответы с поиском по заголовку/содержимому и сортировкой.
     *
     * @queryParam search string Поиск по заголовку или содержимому
     * @queryParam is_published boolean Фильтр по публикации
     * @queryParam sort_by string Сортировка: id, title, sort_order, created_at. Default: sort_order
     * @queryParam sort_order string asc или desc. Default: asc
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = Faq::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
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
            'data' => $paginated->getCollection()->map(fn (Faq $faq) => $this->format($faq)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить один вопрос FAQ.
     */
    public function show(Faq $faq): JsonResponse
    {
        return response()->json(['data' => $this->format($faq)]);
    }

    /**
     * Создать вопрос FAQ.
     *
     * По умолчанию вопрос создаётся опубликованным с sort_order = 0.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'boolean',
        ]);

        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_published'] = $validated['is_published'] ?? true;

        $faq = Faq::create($validated);

        return response()->json(['data' => $this->format($faq)], 201);
    }

    /**
     * Обновить вопрос FAQ.
     *
     * Можно передавать только изменённые поля.
     */
    public function update(Request $request, Faq $faq): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'boolean',
        ]);

        $faq->update($validated);

        return response()->json(['data' => $this->format($faq->fresh())]);
    }

    /**
     * Удалить вопрос FAQ.
     */
    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(null, 204);
    }

    private function format(Faq $faq): array
    {
        return [
            'id' => $faq->id,
            'title' => $faq->title,
            'content' => $faq->content,
            'sort_order' => $faq->sort_order,
            'is_published' => (bool) $faq->is_published,
            'created_at' => $faq->created_at?->toIso8601String(),
            'updated_at' => $faq->updated_at?->toIso8601String(),
        ];
    }
}
