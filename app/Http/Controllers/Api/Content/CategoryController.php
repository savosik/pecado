<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Категории (READ-ONLY).
 *
 * @tags Каталог — Категории
 */
class CategoryController extends Controller
{
    /**
     * Дерево категорий.
     *
     * @queryParam search string Поиск по названию
     * @queryParam parent_id integer Только дочерние указанной категории
     * @queryParam is_active boolean Фильтр по активности. Default: true
     * @queryParam flat boolean Плоский список вместо дерева. Default: false
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        // По умолчанию показываем только активные
        $isActive = $request->input('is_active', true);
        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $flat = $request->boolean('flat', false);

        if ($flat || $request->has('search')) {
            // Плоский список
            $categories = $query->orderBy('name')->get();

            return response()->json([
                'data' => $categories->map(fn (Category $c) => $this->format($c)),
            ]);
        }

        // Дерево
        $tree = Category::where('is_active', true)
            ->defaultOrder()
            ->get()
            ->toTree();

        return response()->json([
            'data' => $this->formatTree($tree),
        ]);
    }

    /**
     * Одна категория с дочерними.
     */
    public function show(Category $category): JsonResponse
    {
        $children = $category->children()
            ->where('is_active', true)
            ->defaultOrder()
            ->get();

        return response()->json([
            'data' => [
                ...$this->format($category),
                'children' => $children->map(fn (Category $c) => $this->format($c)),
                'products_count' => $category->products()->count(),
                'ancestors' => $category->ancestors->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                ]),
            ],
        ]);
    }

    private function format(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'is_active' => (bool) $category->is_active,
            'is_group' => (bool) $category->is_group,
            'short_description' => $category->short_description,
            'description' => $category->description,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
        ];
    }

    private function formatTree($nodes): array
    {
        return $nodes->map(function (Category $node) {
            $data = $this->format($node);
            $data['children'] = $this->formatTree($node->children);

            return $data;
        })->toArray();
    }
}
