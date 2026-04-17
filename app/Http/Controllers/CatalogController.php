<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /**
     * Поиск каталога товаров.
     *
     * GET /catalog?search=вибратор
     *
     * При наличии search — использует Product::search() через Laravel Scout.
     * При отсутствии search — обычная постраничная выдача.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = min(max((int) $request->input('per_page', 20), 5), 100);

        if ($search) {
            // Полнотекстовый поиск через Meilisearch
            $searchBuilder = Product::search($search)
                ->query(fn ($q) => $q->with(['brand', 'category', 'media']));

            if ($hybridOptions = $this->getHybridSearchOptions()) {
                $searchBuilder->options($hybridOptions);
            }

            $products = $searchBuilder
                ->paginate($perPage)
                ->withQueryString();
        } else {
            // Обычная постраничная выдача
            $products = Product::query()
                ->with(['brand', 'category', 'media'])
                ->orderBy('id', 'desc')
                ->paginate($perPage)
                ->withQueryString();
        }

        return response()->json([
            'products' => $products,
            'search' => $search,
        ]);
    }

    /**
     * Поисковые подсказки (Autocomplete).
     *
     * GET /api/search/suggestions?q=виб
     *
     * Возвращает JSON с двумя блоками:
     * - products (до 8 товаров) — id, название, цена, изображение, URL, рейтинг
     * - categories (до 4 категорий) — id, название, иконка, URL
     */
    public function suggestions(Request $request): JsonResponse
    {
        $query = $request->input('q', '');

        if (mb_strlen($query) < 1) {
            return response()->json([
                'products' => [],
                'categories' => [],
            ]);
        }

        // Поиск товаров (до 8)
        $searchBuilder = Product::search($query)
            ->query(fn ($q) => $q->with(['brand', 'media']));

        if ($hybridOptions = $this->getHybridSearchOptions()) {
            $searchBuilder->options($hybridOptions);
        }

        $products = $searchBuilder
            ->take(8)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->base_price,
                'image' => $product->getFirstMediaUrl('main'),
                'url' => '/catalog/'.$product->slug,
                'brand' => $product->brand?->name,
                'sku' => $product->sku,
            ]);

        // Поиск категорий (до 4)
        $categories = Category::search($query)
            ->take(4)
            ->get()
            ->filter(fn (Category $category) => $category->is_active)
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => $category->getFirstMediaUrl('icon'),
                'url' => '/catalog?category='.$category->id,
            ]);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    /**
     * Получить опции гибридного поиска для Meilisearch.
     */
    private function getHybridSearchOptions(): ?array
    {
        if (! config('search.hybrid.enabled')) {
            return null;
        }

        return [
            'hybrid' => [
                'embedder' => config('search.hybrid.embedder'),
                'semanticRatio' => config('search.hybrid.semantic_ratio'),
            ],
        ];
    }
}
