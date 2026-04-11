<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API для получения товаров по массиву ID.
 * Используется блоком productCarousel в контентных блоках.
 */
class ProductByIdsController extends Controller
{
    /**
     * GET /api/products/by-ids?ids=1,2,3
     */
    public function __invoke(Request $request): JsonResponse
    {
        $idsParam = $request->input('ids', '');
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));

        if (empty($ids) || count($ids) > 20) {
            return response()->json([]);
        }

        $query = Product::query()
            ->whereIn('id', $ids)
            ->with(ProductQueryService::productEagerLoads());

        $query->select('products.*');
        ProductQueryService::withRegionStockSums($query);

        $products = $query->get()
            ->map(fn(Product $p) => ProductQueryService::productToArray($p))
            ->values()
            ->toArray();

        $products = ProductQueryService::enrichProductsWithDiscounts($products);
        $products = ProductQueryService::convertProductsPrices($products);

        return response()->json($products);
    }
}
