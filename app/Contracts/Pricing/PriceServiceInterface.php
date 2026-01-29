<?php

namespace App\Contracts\Pricing;

use App\Models\Product;
use App\Models\User;

interface PriceServiceInterface
{
    /**
     * Get the base price of the product in the base currency.
     */
    public function getBasePrice(Product $product): float;

    /**
     * Get the price of the product for a specific user in the base currency.
     */
    public function getUserPrice(Product $product, ?User $user = null): float;
}
