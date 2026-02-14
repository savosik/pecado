<?php

namespace App\Services;

use App\Models\Currency;

class CurrencyService
{
    /**
     * Конвертировать цену из базовой валюты в указанную.
     *
     * Формула: convertedPrice = basePrice / (exchange_rate * correction_factor)
     * Пример: 1000 RUB → BYN при exchange_rate=27.16, correction_factor=1.0 → 1000/27.16 ≈ 36.82 Br
     */
    public function convertFromBase(float $price, Currency $currency): float
    {
        if ($currency->is_base) {
            return $price;
        }

        $rate = (float) ($currency->exchange_rate ?: 1.0);
        $factor = (float) ($currency->correction_factor ?: 1.0);

        $effectiveRate = $rate * $factor;

        if ($effectiveRate <= 0) {
            return $price;
        }

        return round($price / $effectiveRate, 2);
    }

    /**
     * Конвертировать массив цен товаров из базовой валюты.
     *
     * Обрабатывает поля base_price и sale_price.
     */
    public function convertProductPrices(array $products, Currency $currency): array
    {
        if ($currency->is_base) {
            return $products;
        }

        return array_map(function (array $product) use ($currency) {
            if (isset($product['base_price'])) {
                $product['base_price'] = $this->convertFromBase((float) $product['base_price'], $currency);
            }
            if (isset($product['sale_price'])) {
                $product['sale_price'] = $this->convertFromBase((float) $product['sale_price'], $currency);
            }
            return $product;
        }, $products);
    }
}
