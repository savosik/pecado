<?php

namespace App\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;

class PriceService implements PriceServiceInterface
{
    public function __construct(
        protected CurrencyConversionServiceInterface $currencyService,
        protected \App\Contracts\Currency\UserCurrencyResolverInterface $currencyResolver
    ) {}

    /**
     * Get the base price of the product in the base currency.
     */
    public function getBasePrice(Product $product): float
    {
        return (float) $product->base_price;
    }

    /**
     * Get the price of the product for a specific user in their preferred currency (or base if none).
     * Applies the maximum active discount for the user/product combination.
     */
    public function getUserPrice(Product $product, ?User $user = null): float
    {
        $basePrice = $this->getBasePrice($product);
        $discountedPrice = $basePrice;

        if ($user) {
            // Find the maximum active discount for this user and product
            $maxDiscount = $this->getMaxDiscountPercentage($user, $product);
            if ($maxDiscount > 0) {
                $discountedPrice = $basePrice * (1 - $maxDiscount / 100);
            }

            $currency = $this->currencyResolver->resolve($user);
            if ($currency) {
                return $this->convertPrice($discountedPrice, $currency);
            }
        }

        return $discountedPrice;
    }

    /**
     * Get the maximum active discount percentage for a user and product.
     */
    protected function getMaxDiscountPercentage(User $user, Product $product): float
    {
        return Discount::where('is_posted', true)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
            ->max('percentage') ?? 0.0;
    }

    /**
     * Get the price of the product in a specific currency.
     */
    public function getCurrencyPrice(Product $product, Currency $currency): float
    {
        $basePrice = $this->getBasePrice($product);
        return $this->convertPrice($basePrice, $currency);
    }

    /**
     * Convert an arbitrary amount from base currency to target currency.
     */
    public function convertPrice(float $amount, Currency $currency): float
    {
        return $this->currencyService->convertFromBase($amount, $currency);
    }
}
