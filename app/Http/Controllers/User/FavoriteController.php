<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FavoriteController extends Controller
{
    /**
     * Страница «Избранное» — Inertia-рендер с пагинацией.
     * GET /favorites
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // ВАЖНО: select('products.*') ДОЛЖЕН идти ДО withRegionStockSums(),
        // иначе addSelect sub-selects для stock будут перезатёрты.
        $query = Product::query()
            ->join('favorites', 'products.id', '=', 'favorites.product_id')
            ->where('favorites.user_id', $user->id)
            ->orderByDesc('favorites.created_at')
            ->select('products.*')
            ->with(ProductQueryService::productEagerLoads());

        ProductQueryService::withRegionStockSums($query);

        $paginated = $query->paginate(20);
        $products = collect($paginated->items())->map(function ($product) {
            return ProductQueryService::productToArray($product);
        })->toArray();

        // Обогатить скидками и конвертировать валюту
        $products = ProductQueryService::enrichProductsWithDiscounts($products);
        $products = ProductQueryService::convertProductsPrices($products);

        return Inertia::render('User/Favorites/Index', [
            'favorites' => [
                'data'         => $products,
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить список ID избранных товаров текущего пользователя.
     * GET /api/favorites/ids
     */
    public function ids(Request $request): JsonResponse
    {
        $productIds = $request->user()
            ->favorites()
            ->pluck('product_id')
            ->toArray();

        return response()->json([
            'product_ids' => $productIds,
        ]);
    }

    /**
     * Переключить товар в избранном (toggle).
     * POST /api/favorites/{product}/toggle
     *
     * Атомарный подход: сначала пробуем удалить, если ничего не удалено — создаём.
     * Защищает от дублей при двойном клике.
     */
    public function toggle(Request $request, Product $product): JsonResponse
    {
        $deleted = $request->user()
            ->favorites()
            ->where('product_id', $product->id)
            ->delete();

        if ($deleted) {
            return response()->json(['removed' => true]);
        }

        $request->user()->favorites()->firstOrCreate([
            'product_id' => $product->id,
        ]);

        return response()->json(['added' => true]);
    }
}
