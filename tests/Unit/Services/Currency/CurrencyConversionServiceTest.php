<?php

namespace Tests\Unit\Services\Currency;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Models\Currency;
use App\Services\Currency\CurrencyConversionService;
use Mockery;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    protected CurrencyConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CurrencyConversionService();
    }

    public function test_it_converts_from_base()
    {
        // Base is RUB preferably
        $baseCurrency = Mockery::mock(Currency::class)->makePartial();
        $baseCurrency->is_base = true;

        $targetCurrency = Mockery::mock(Currency::class)->makePartial();
        $targetCurrency->is_base = false;
        $targetCurrency->code = 'KZT';
        $targetCurrency->exchange_rate = 0.2; // 1 KZT = 0.2 RUB
        $targetCurrency->correction_factor = 1.0; 

        // Amount in Base (RUB) = 100
        // Expected in Target (KZT) = 100 / 0.2 = 500
        $converted = $this->service->convertFromBase(100.0, $targetCurrency);

        $this->assertEquals(500.0, $converted);
    }

    public function test_it_applies_correction_factor()
    {
        $targetCurrency = Mockery::mock(Currency::class)->makePartial();
        $targetCurrency->is_base = false;
        $targetCurrency->exchange_rate = 0.5; 
        $targetCurrency->correction_factor = 1.1; // 10% increase

        // Amount 100. Converted = 100 / 0.5 = 200. With factor = 220.
        $converted = $this->service->convertFromBase(100.0, $targetCurrency);
        
        $this->assertEqualsWithDelta(220.0, $converted, 0.0001);
    }
}
