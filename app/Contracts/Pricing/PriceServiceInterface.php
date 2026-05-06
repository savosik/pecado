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
     * Get the base price of the product converted to the user's preferred currency (no discounts).
     */
    public function getBasePriceForUser(Product $product, ?User $user = null): float;

    /**
     * Get the price of the product for a specific user in their preferred currency (or base if none).
     * Uses individual prices from 1С if available.
     */
    public function getUserPrice(Product $product, ?User $user = null, ?int $warehouseId = null): float;

    /**
     * Get the full price result with base_price, individual_price, discount_percent.
     */
    public function getPriceResult(Product $product, ?User $user = null, ?int $warehouseId = null): PriceResult;

    /**
     * Получить карту PriceResult для коллекции товаров одним батч-запросом.
     * Ключ массива — product_id, значение — PriceResult с учётом индивидуальной цены пользователя.
     *
     * @param  iterable<Product>  $products
     * @return array<int, PriceResult>
     */
    public function getPriceMapForProducts(iterable $products, ?User $user = null, ?int $warehouseId = null): array;

    /**
     * Get the price of the product in a specific currency.
     */
    public function getCurrencyPrice(Product $product, Currency $currency): float;

    /**
     * Convert an arbitrary amount from base currency to target currency.
     */
    public function convertPrice(float $amount, Currency $currency): float;
}
