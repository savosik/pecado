<?php

namespace App\Services\Currency;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Models\Currency;
use InvalidArgumentException;

class CurrencyConversionService implements CurrencyConversionServiceInterface
{
    /**
     * Конвертировать сумму из одной валюты в другую.
     *
     * exchange_rate = «сколько RUB стоит 1 единица иностранной валюты».
     * По US-04 это уже итоговый курс (official_rate × rate_coefficient из 1С).
     * rate_coefficient НЕ применяется повторно при конвертации.
     */
    public function convert(float $amount, Currency $from, Currency $to): float
    {
        if ($from->is($to)) {
            return $amount;
        }

        if ($from->is_base) {
            return $this->convertFromBase($amount, $to);
        }

        // Иностранная → RUB: amount × exchange_rate
        $amountInBase = $amount * $from->exchange_rate;

        if ($to->is_base) {
            return $amountInBase;
        }

        // RUB → другая иностранная
        return $this->convertFromBase($amountInBase, $to);
    }

    /**
     * Конвертировать сумму из базовой валюты (RUB) в указанную.
     *
     * Формула: amount / exchange_rate
     * Пример: 1000 RUB → BYN при exchange_rate=28.5 → 1000/28.5 ≈ 35.09 Br
     *
     * exchange_rate по US-04 — уже итоговый курс (official_rate × rate_coefficient из 1С).
     * rate_coefficient НЕ применяется здесь повторно.
     */
    public function convertFromBase(float $amount, Currency $to): float
    {
        if ($to->is_base) {
            return $amount;
        }

        if ($to->exchange_rate == 0) {
            throw new InvalidArgumentException("Exchange rate for {$to->code} cannot be zero.");
        }

        return round($amount / $to->exchange_rate, 2);
    }
}
