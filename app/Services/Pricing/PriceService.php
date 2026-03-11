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
     * Get the base price of the product converted to the user's preferred currency (no discounts).
     */
    public function getBasePriceForUser(Product $product, ?User $user = null): float
    {
        $basePrice = $this->getBasePrice($product);

        if ($user) {
            $currency = $this->currencyResolver->resolve($user);
            if ($currency) {
                return $this->convertPrice($basePrice, $currency);
            }
        }

        return $basePrice;
    }

    /**
     * Get the price of the product for a specific user in their preferred currency (or base if none).
     * Applies the maximum active discount for the user/product combination.
     */
    public function getUserPrice(Product $product, ?User $user = null): float
    {
        $discountedPrice = $user 
            ? $this->getDiscountedPrice($product, $user)
            : $this->getBasePrice($product);

        if ($user) {
            $currency = $this->currencyResolver->resolve($user);
            if ($currency) {
                return $this->convertPrice($discountedPrice, $currency);
            }
        }

        return $discountedPrice;
    }

    /**
     * Get the price of the product for a specific user in the base currency, applying discounts.
     */
    public function getDiscountedPrice(Product $product, User $user): float
    {
        $basePrice = $this->getBasePrice($product);
        $maxDiscount = $this->getMaxDiscountPercentage($user, $product);
        
        if ($maxDiscount > 0) {
            return $basePrice * (1 - $maxDiscount / 100);
        }

        return $basePrice;
    }

    /**
     * Get the maximum active discount percentage for a user and product.
     *
     * US-03 v2: Партнёр подходит под скидку если привязан напрямую ИЛИ через сегмент партнёров.
     * Товар подходит если привязан напрямую ИЛИ через сегмент номенклатуры.
     * Из всех применимых скидок берётся один максимальный процент (без суммирования).
     * Акции (promotion) учитываются только если текущая дата входит в [starts_at, ends_at].
     */
    protected function getMaxDiscountPercentage(User $user, Product $product): float
    {
        return Discount::where('is_posted', true)
            // Партнёр: прямая привязка ИЛИ через сегмент партнёров
            ->where(function ($q) use ($user) {
                $q->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                  ->orWhereHas('partnerSegments.users', fn ($q) => $q->where('users.id', $user->id));
            })
            // Товар: прямая привязка ИЛИ через сегмент номенклатуры
            ->where(function ($q) use ($product) {
                $q->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
                  ->orWhereHas('productSegments.products', fn ($q) => $q->where('products.id', $product->id));
            })
            // Временные акции (promotion) действуют только в своём диапазоне дат
            ->where(function ($q) {
                $q->where('type', 'agreement')
                  ->orWhere(function ($q) {
                      $q->where('type', 'promotion')
                        ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
                  });
            })
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
