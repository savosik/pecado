<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductSelection;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /**
     * Дерево категорий для каталог-панели.
     */
    public function categories(): JsonResponse
    {
        $tree = Category::active()->defaultOrder()->get()->toTree();

        $mapNode = function ($node) use (&$mapNode) {
            $iconUrl = $node->getFirstMediaUrl('icon');

            return [
                'id' => $node->id,
                'name' => $node->name,
                'slug' => $node->slug,
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
        $brands = Brand::with('tags')
            ->orderBy('name')
            ->get()
            ->map(function (Brand $brand) {
                $logoUrl = $brand->getFirstMediaUrl('logo');

                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'category' => $brand->category?->value,
                    'is_featured' => $brand->is_featured,
                    'logo_url' => $logoUrl ?: null,
                    'tags' => $brand->tags->map(fn ($tag) => [
                        'name' => $tag->getTranslation('name', 'en'),
                        'color' => $tag->type,
                    ])->values()->toArray(),
                ];
            });

        return response()->json([
            'data' => $brands->values()->toArray(),
        ]);
    }

    /**
     * Список активных подборок для каталог-панели.
     */
    public function selections(): JsonResponse
    {
        $selections = ProductSelection::active()
            ->ordered()
            ->get(['id', 'name', 'slug', 'short_description']);

        return response()->json([
            'data' => $selections,
        ]);
    }
}
