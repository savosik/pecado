<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            $products = Product::search($search)
                ->query(fn ($q) => $q->with(['brand', 'category', 'media']))
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
        $products = Product::search($query)
            ->query(fn ($q) => $q->with(['brand', 'media']))
            ->take(8)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->base_price,
                'image' => $product->getFirstMediaUrl('main'),
                'url' => '/catalog/' . $product->slug,
                'brand' => $product->brand?->name,
                'sku' => $product->sku,
            ]);

        // Поиск категорий (до 4)
        $categories = Category::search($query)
            ->take(4)
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => $category->getFirstMediaUrl('icon'),
                'url' => '/catalog?category=' . $category->id,
            ]);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
        ]);
    }
}
