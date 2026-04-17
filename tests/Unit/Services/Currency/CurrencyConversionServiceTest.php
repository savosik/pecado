<?php

namespace Tests\Unit\Services\Currency;

use App\Models\Currency;
use App\Services\Currency\CurrencyConversionService;
use Tests\TestCase;

/**
 * Тесты конвертации валют по правилам US-04.
 *
 * exchange_rate — итоговый курс (official_rate × rate_coefficient из 1С).
 * rate_coefficient НЕ применяется повторно при конвертации.
 */
class CurrencyConversionServiceTest extends TestCase
{
    protected CurrencyConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CurrencyConversionService;
    }

    public function test_it_converts_from_base(): void
    {
        $targetCurrency = new Currency;
        $targetCurrency->is_base = false;
        $targetCurrency->code = 'KZT';
        // exchange_rate = уже итоговый курс (official_rate × rate_coefficient)
        $targetCurrency->exchange_rate = 0.2; // 1 KZT = 0.2 RUB

        // 100 RUB / 0.2 = 500 KZT
        $converted = $this->service->convertFromBase(100.0, $targetCurrency);

        $this->assertEquals(500.0, $converted);
    }

    public function test_it_converts_from_base_byn(): void
    {
        $targetCurrency = new Currency;
        $targetCurrency->is_base = false;
        $targetCurrency->code = 'BYN';
        $targetCurrency->exchange_rate = 28.5; // 1 BYN = 28.5 RUB

        // 1000 RUB / 28.5 ≈ 35.09 BYN
        $converted = $this->service->convertFromBase(1000.0, $targetCurrency);

        $this->assertEqualsWithDelta(35.09, $converted, 0.01);
    }

    public function test_it_returns_same_amount_for_base_currency(): void
    {
        $baseCurrency = new Currency;
        $baseCurrency->is_base = true;
        $baseCurrency->exchange_rate = 1.0;

        $converted = $this->service->convertFromBase(100.0, $baseCurrency);

        $this->assertEquals(100.0, $converted);
    }

    public function test_exchange_rate_already_includes_rate_coefficient(): void
    {
        // По US-04: exchange_rate = official_rate × rate_coefficient
        // official_rate=5.40, rate_coefficient=1.01 → exchange_rate=5.454
        $targetCurrency = new Currency;
        $targetCurrency->is_base = false;
        $targetCurrency->exchange_rate = 5.454; // уже итоговый

        // 1000 RUB / 5.454 ≈ 183.35 KZT (round 2 decimals)
        $converted = $this->service->convertFromBase(1000.0, $targetCurrency);

        $this->assertEqualsWithDelta(183.35, $converted, 0.01);
    }
}
