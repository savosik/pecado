<?php

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCurrencyRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_update_command_fetches_and_updates_rates()
    {
        // Seed initial currencies
        Currency::create(['code' => 'RUB', 'name' => 'Rub', 'symbol' => '₽', 'is_base' => true, 'exchange_rate' => 1]);
        Currency::create(['code' => 'BYN', 'name' => 'Byn', 'symbol' => 'Br', 'is_base' => false, 'exchange_rate' => 1]);
        Currency::create(['code' => 'KZT', 'name' => 'Kzt', 'symbol' => '₸', 'is_base' => false, 'exchange_rate' => 1]);

        // Mock CBR API response
        $xmlContent = <<<XML
<?xml version="1.0" encoding="windows-1251"?>
<ValCurs Date="29.01.2026" name="Foreign Currency Market">
    <Valute ID="R01090B">
        <NumCode>933</NumCode>
        <CharCode>BYN</CharCode>
        <Nominal>1</Nominal>
        <Name>Belarussian Ruble</Name>
        <Value>28,5000</Value>
    </Valute>
    <Valute ID="R01335">
        <NumCode>398</NumCode>
        <CharCode>KZT</CharCode>
        <Nominal>100</Nominal>
        <Name>Kazakhstan Tenge</Name>
        <Value>20,0000</Value>
    </Valute>
</ValCurs>
XML;

        Http::fake([
            'www.cbr.ru/*' => Http::response($xmlContent, 200),
        ]);

        // Run the command
        $this->artisan('currency:update')
             ->assertSuccessful();

        // Verify database updates
        $this->assertDatabaseHas('currencies', [
            'code' => 'BYN',
            'exchange_rate' => 28.5, // 28.5 / 1
        ]);

        // 20.0000 / 100 = 0.2
        $this->assertDatabaseHas('currencies', [
            'code' => 'KZT',
            'exchange_rate' => 0.2,
        ]);
        
        // Base currency should remain unchanged
         $this->assertDatabaseHas('currencies', [
            'code' => 'RUB',
            'exchange_rate' => 1,
        ]);
    }
}
