<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
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
     * Добавить товар в избранное.
     * POST /api/favorites/{product}
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $request->user()->favorites()->firstOrCreate([
            'product_id' => $product->id,
        ]);

        return response()->json([
            'message' => 'Товар добавлен в избранное',
        ], 201);
    }

    /**
     * Удалить товар из избранного.
     * DELETE /api/favorites/{product}
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()
            ->favorites()
            ->where('product_id', $product->id)
            ->delete();

        return response()->json([
            'message' => 'Товар удалён из избранного',
        ]);
    }
}
