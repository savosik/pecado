<?php

namespace App\Services\Pricing;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Product;
use App\Models\User;

class PriceService implements PriceServiceInterface
{
    /**
     * Get the base price of the product in the base currency.
     */
    public function getBasePrice(Product $product): float
    {
        return (float) $product->base_price;
    }

    /**
     * Get the price of the product for a specific user in the base currency.
     */
    public function getUserPrice(Product $product, ?User $user = null): float
    {
        // TODO: Implement user-specific pricing logic (discounts, markups, etc.)
        return (float) $product->base_price;
    }
}
