<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Регионы (READ-ONLY).
 *
 * Используйте region_id из этого списка в запросах к каталогу товаров
 * для получения цен и наличия в конкретном регионе.
 *
 * @tags Каталог — Регионы
 */
class RegionController extends Controller
{
    /**
     * Список регионов.
     *
     * Возвращает все доступные регионы с привязанными складами.
     * Используйте `id` региона как параметр `region_id` в запросах
     * к /products, /products/search, /products/facets, /products/price-intervals.
     */
    public function index(): JsonResponse
    {
        $regions = Region::query()
            ->withCount(['primaryWarehouses', 'preorderWarehouses'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $regions->map(fn (Region $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'primary_warehouses_count' => $r->primary_warehouses_count,
                'preorder_warehouses_count' => $r->preorder_warehouses_count,
            ]),
        ]);
    }
}
