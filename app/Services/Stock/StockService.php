<?php

namespace App\Services\Stock;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockService implements StockServiceInterface
{
    /**
     * Get the stock information for a product for a specific user.
     * Returns array with 'available' (from primary warehouses) and 'preorder' (from preorder warehouses) quantities.
     *
     * @return array{available: int, preorder: int}
     */
    public function getStock(Product $product, ?User $user = null): array
    {
        return [
            'available' => $this->getAvailableStock($product, $user),
            'preorder' => $this->getPreorderStock($product, $user),
        ];
    }

    /**
     * Get the available stock quantity for a product for a specific user.
     * This is the sum of stock from all primary warehouses in the user's region.
     */
    public function getAvailableStock(Product $product, ?User $user = null): int
    {
        return $this->sumByWarehouseType($product, $user, 'primary');
    }

    /**
     * Get the preorder stock quantity for a product for a specific user.
     * This is the sum of stock from all preorder warehouses in the user's region.
     */
    public function getPreorderStock(Product $product, ?User $user = null): int
    {
        return $this->sumByWarehouseType($product, $user, 'preorder');
    }

    /**
     * Получить карту доступных остатков для коллекции товаров одним SQL.
     * Делает 2 запроса независимо от размера коллекции:
     *   1) region_warehouse → warehouse_id для primary-складов региона;
     *   2) product_warehouse → SUM(quantity) GROUP BY product_id.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function getAvailableStockMap(iterable $products, ?User $user = null): array
    {
        return $this->stockMapByWarehouseType($products, $user, 'primary');
    }

    /**
     * Симметричная getAvailableStockMap карта по preorder-складам.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function getPreorderStockMap(iterable $products, ?User $user = null): array
    {
        return $this->stockMapByWarehouseType($products, $user, 'preorder');
    }

    /**
     * Общий батч-запрос остатков по типу склада (primary/preorder).
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    private function stockMapByWarehouseType(iterable $products, ?User $user, string $type): array
    {
        $result = [];
        $productIds = [];
        foreach ($products as $product) {
            $result[$product->id] = 0;
            $productIds[] = (int) $product->id;
        }

        if ($productIds === []) {
            return $result;
        }

        $regionId = $this->resolveRegionId($user);
        if (! $regionId) {
            return $result;
        }

        $warehouseIds = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->where('type', $type)
            ->pluck('warehouse_id');

        if ($warehouseIds->isEmpty()) {
            return $result;
        }

        $rows = DB::table('product_warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->product_id] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Резолвит ID региона пользователя с fallback на регион по умолчанию.
     * Если у пользователя не задан region_id (например, у админа), используется Region::defaultId() —
     * та же логика, что в каталоге (CatalogApiController), чтобы наличие в карточке и в корзине совпадало.
     */
    private function resolveRegionId(?User $user): ?int
    {
        if ($user !== null && $user->region_id !== null) {
            return (int) $user->region_id;
        }

        return Region::defaultId();
    }

    private function sumByWarehouseType(Product $product, ?User $user, string $type): int
    {
        $regionId = $this->resolveRegionId($user);
        if (! $regionId) {
            return 0;
        }

        $warehouseIds = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->where('type', $type)
            ->pluck('warehouse_id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        return (int) $product->warehouses()
            ->whereIn('warehouses.id', $warehouseIds)
            ->sum('product_warehouse.quantity');
    }
}
