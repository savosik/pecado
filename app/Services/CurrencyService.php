<?php

namespace App\Services;

use App\Models\Currency;

class CurrencyService
{
    /**
     * Конвертировать цену из базовой валюты в указанную.
     *
     * По US-04, exchange_rate — уже итоговый курс (official_rate × rate_coefficient из 1С).
     * Формула: convertedPrice = basePrice / exchange_rate
     * Пример: 1000 RUB → BYN при exchange_rate=28.5 → 1000/28.5 ≈ 35.09 Br
     */
    public function convertFromBase(float $price, Currency $currency): float
    {
        if ($currency->is_base) {
            return $price;
        }

        $rate = (float) ($currency->exchange_rate ?: 1.0);

        if ($rate <= 0) {
            return $price;
        }

        return round($price / $rate, 2);
    }

    /**
     * Конвертировать цену из пользовательской валюты обратно в базовую.
     *
     * Обратная операция к convertFromBase.
     * По US-04: basePrice = convertedPrice × exchange_rate
     */
    public function convertToBase(float $price, Currency $currency): float
    {
        if ($currency->is_base) {
            return $price;
        }

        $rate = (float) ($currency->exchange_rate ?: 1.0);

        if ($rate <= 0) {
            return $price;
        }

        return round($price * $rate, 2);
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
