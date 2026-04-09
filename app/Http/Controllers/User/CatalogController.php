<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

            // Сортируем подкатегории: те, у которых больше потомков — первыми
            $sortedChildren = $node->children
                ->sortByDesc(fn ($child) => $child->children->count())
                ->values();

            return [
                'id' => $node->id,
                'name' => $node->name,
                'slug' => $node->slug,
                'parent_id' => $node->parent_id,
                'icon_url' => $iconUrl ?: null,
                'children' => $sortedChildren->map($mapNode)->toArray(),
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
        $brands = Brand::with(['tags', 'children' => fn($q) => $q->orderBy('name')])
            ->whereNull('parent_id')
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
                    'children' => $brand->children->map(fn ($child) => [
                        'id' => $child->id,
                        'name' => $child->name,
                        'slug' => $child->slug,
                        'category' => $child->category?->value,
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
            ->forRegion(Auth::user()?->region_id)
            ->get(['id', 'name', 'slug', 'short_description']);

        return response()->json([
            'data' => $selections,
        ]);
    }
}
