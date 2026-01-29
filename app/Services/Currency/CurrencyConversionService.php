<?php

namespace App\Services\Currency;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Models\Currency;
use InvalidArgumentException;

class CurrencyConversionService implements CurrencyConversionServiceInterface
{
    /**
     * Convert an amount from one currency to another.
     */
    public function convert(float $amount, Currency $from, Currency $to): float
    {
        if ($from->is($to)) {
            return $amount;
        }

        // If from is base, just convert to target
        if ($from->is_base) {
            return $this->convertFromBase($amount, $to);
        }

        // If to is base, convert back to base
        // Formula: To Base = Amount / (1 / ExchangeRate) * CorrectionFactor? 
        // Wait, exchange_rate in DB is "How many Base Units is 1 Foreign Unit" (based on my previous analysis)
        // OR "How many Foreign Units is 1 Base Unit"?
        
        // Let's re-read UpdateCurrencyRates.php logic I saw earlier.
        // $rate = $value / $nominal; 
        // $value is in RUB (Base). $nominal is amount of foreign currency.
        // So 1 Foreign = $rate Base.
        // Example: 1 USD = 90 RUB. rate = 90.
        
        // To convert Foreign -> Base:
        // Amount (USD) * Rate (90) = Amount (RUB).
        
        // So:
        $amountInBase = $amount * $from->exchange_rate;

        // If target is base, we are done. (ignoring correction factor for base for now as it's typically 1)
        if ($to->is_base) {
             return $amountInBase;
        }

        // Now convert Base -> Target
        return $this->convertFromBase($amountInBase, $to);
    }

    /**
     * Convert an amount from the base currency to a target currency.
     */
    public function convertFromBase(float $amount, Currency $to): float
    {
        if ($to->is_base) {
            return $amount;
        }

        // Rate is "1 Foreign = X Base". 
        // Example: 1 USD = 90 RUB.
        // We have 180 RUB. How many USD? 
        // 180 / 90 = 2 USD.
        // So: Amount / ExchangeRate.
        
        if ($to->exchange_rate == 0) {
            throw new InvalidArgumentException("Exchange rate for {$to->code} cannot be zero.");
        }

        $converted = $amount / $to->exchange_rate;
        
        // Apply correction factor if exists
        // correction_factor defaults to 1.
        // If we want to add 5% margin, factor might be 1.05? Or should it be applied to the rate?
        // "adjustable coefficient, defaulting to 1"
        // Usually price = (Base / Rate) * Coefficient.
        
        return $converted * $to->correction_factor;
    }
}
