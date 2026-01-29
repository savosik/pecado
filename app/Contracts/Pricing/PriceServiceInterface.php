<?php

namespace App\Contracts\Pricing;

use App\Models\Currency;
use App\Models\Product;
use App\Models\User;

interface PriceServiceInterface
{
    /**
     * Get the base price of the product in the base currency.
     */
    public function getBasePrice(Product $product): float;

    /**
     * Get the price of the product for a specific user in their preferred currency (or base if none).
     */
    public function getUserPrice(Product $product, ?User $user = null): float;

    /**
     * Get the price of the product for a specific user in the base currency, applying discounts.
     */
    public function getDiscountedPrice(Product $product, User $user): float;

    /**
     * Get the price of the product in a specific currency.
     */
    public function getCurrencyPrice(Product $product, Currency $currency): float;

    /**
     * Convert an arbitrary amount from base currency to target currency.
     */
    public function convertPrice(float $amount, Currency $currency): float;
}
