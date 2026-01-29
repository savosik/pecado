<?php

namespace App\Services\Stock;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Models\User;

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
        if (!$user || !$user->region) {
            return 0;
        }

        $warehouseIds = $user->region->primaryWarehouses()->pluck('warehouses.id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        return (int) $product->warehouses()
            ->whereIn('warehouses.id', $warehouseIds)
            ->sum('product_warehouse.quantity');
    }

    /**
     * Get the preorder stock quantity for a product for a specific user.
     * This is the sum of stock from all preorder warehouses in the user's region.
     */
    public function getPreorderStock(Product $product, ?User $user = null): int
    {
        if (!$user || !$user->region) {
            return 0;
        }

        $warehouseIds = $user->region->preorderWarehouses()->pluck('warehouses.id');

        if ($warehouseIds->isEmpty()) {
            return 0;
        }

        return (int) $product->warehouses()
            ->whereIn('warehouses.id', $warehouseIds)
            ->sum('product_warehouse.quantity');
    }
}
