<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /**
     * Дерево категорий для каталог-панели.
     */
    public function categories(): JsonResponse
    {
        $tree = Category::defaultOrder()->get()->toTree();

        $mapNode = function ($node) use (&$mapNode) {
            $iconUrl = $node->getFirstMediaUrl('icon');

            return [
                'id' => $node->id,
                'name' => $node->name,
                'parent_id' => $node->parent_id,
                'icon_url' => $iconUrl ?: null,
                'children' => $node->children->map($mapNode)->values()->toArray(),
            ];
        };

        return response()->json([
            'categories' => $tree->map($mapNode)->values()->toArray(),
        ]);
    }

    /**
     * Список всех брендов для каталог-панели.
     */
    public function brands(): JsonResponse
    {
        $brands = Brand::orderBy('name')
            ->get()
            ->map(function (Brand $brand) {
                $logoUrl = $brand->getFirstMediaUrl('logo');

                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'category' => $brand->category?->value,
                    'logo_url' => $logoUrl ?: null,
                ];
            });

        return response()->json([
            'data' => $brands->values()->toArray(),
        ]);
    }
}
