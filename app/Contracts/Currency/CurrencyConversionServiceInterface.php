<?php

namespace App\Contracts\Currency;

use App\Models\Currency;

interface CurrencyConversionServiceInterface
{
    /**
     * Convert an amount from one currency to another.
     *
     * @param float $amount
     * @param Currency $from
     * @param Currency $to
     * @return float
     */
    public function convert(float $amount, Currency $from, Currency $to): float;

    /**
     * Convert an amount from the base currency to a target currency.
     *
     * @param float $amount
     * @param Currency $to
     * @return float
     */
    public function convertFromBase(float $amount, Currency $to): float;
}
