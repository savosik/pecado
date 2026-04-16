<?php

namespace App\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Currency;
use App\Models\Product;
use App\Models\User;

class PriceService implements PriceServiceInterface
{
    public function __construct(
        protected CurrencyConversionServiceInterface $currencyService,
        protected UserCurrencyResolverInterface $currencyResolver
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
     * Get the price of the product for a specific user in their preferred currency.
     * Uses individual_prices from 1С if available, otherwise returns base price.
     */
    public function getUserPrice(Product $product, ?User $user = null, ?int $warehouseId = null): float
    {
        $priceResult = $this->getPriceResult($product, $user, $warehouseId);
        $displayPrice = $priceResult->getDisplayPrice();

        if ($user) {
            $currency = $this->currencyResolver->resolve($user);
            if ($currency) {
                return $this->convertPrice($displayPrice, $currency);
            }
        }

        return $displayPrice;
    }

    /**
     * Get the full price result with base, individual, and discount info.
     *
     * v7: Вместо расчёта скидок на сайте — берём готовую индивидуальную цену из таблицы
     * individual_prices, куда 1С выгружает рассчитанные цены через MinIO.
     */
    public function getPriceResult(Product $product, ?User $user = null, ?int $warehouseId = null): PriceResult
    {
        $basePrice = $this->getBasePrice($product);

        if (!$user || !$user->erp_id) {
            return PriceResult::withoutDiscount($basePrice);
        }

        // v7.1: Ищем по числовым ID (partner_id = user.id, product_id = product.id)
        // Через proxy для graceful degradation при недоступности prices DB
        $individualPrice = IndividualPriceProxy::findPrice($user->id, $product->id, $warehouseId);

        if (!$individualPrice) {
            return PriceResult::withoutDiscount($basePrice);
        }

        return PriceResult::withIndividualPrice($basePrice, (float) $individualPrice->price);
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
