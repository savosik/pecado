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
