<?php

namespace App\Contracts\Stock;

use App\Models\Product;
use App\Models\User;

interface StockServiceInterface
{
    /**
     * Get the stock information for a product for a specific user.
     * Returns array with 'available' (from primary warehouses) and 'preorder' (from preorder warehouses) quantities.
     *
     * @return array{available: int, preorder: int}
     */
    public function getStock(Product $product, ?User $user = null): array;

    /**
     * Get the available stock quantity for a product for a specific user.
     * This is the sum of stock from all primary warehouses in the user's region.
     */
    public function getAvailableStock(Product $product, ?User $user = null): int;

    /**
     * Get the preorder stock quantity for a product for a specific user.
     * This is the sum of stock from all preorder warehouses in the user's region.
     */
    public function getPreorderStock(Product $product, ?User $user = null): int;
}
