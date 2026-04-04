<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Бренды (READ-ONLY).
 *
 * @tags Каталог — Бренды
 */
class BrandController extends Controller
{
    /**
     * Список брендов.
     *
     * @queryParam search string Поиск по названию
     * @queryParam is_featured boolean Только избранные бренды
     * @queryParam per_page integer Записей на странице. Default: 50
     */
    public function index(Request $request): JsonResponse
    {
        $query = Brand::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        $query->orderBy('name');

        $perPage = min(max((int) $request->input('per_page', 50), 5), 200);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'short_description' => $brand->short_description,
                'category' => $brand->category,
                'is_featured' => (bool) $brand->is_featured,
                'logo_url' => $brand->getFirstMediaUrl('logo') ?: null,
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
     * Один бренд с подробностями.
     */
    public function show(Brand $brand): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'short_description' => $brand->short_description,
                'category' => $brand->category,
                'is_featured' => (bool) $brand->is_featured,
                'meta_title' => $brand->meta_title,
                'meta_description' => $brand->meta_description,
                'logo_url' => $brand->getFirstMediaUrl('logo') ?: null,
                'products_count' => $brand->products()->count(),
            ],
        ]);
    }
}
