<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Тесты для кнопки "Обновить курсы" в AdminPanel и команды currency:update.
 *
 * Production: курсы из 1С → RabbitMQ (US-04).
 * AdminPanel-кнопка: обновляет из ЦБ РФ для ручного тестирования.
 */
class UpdateCurrencyRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private string $cbrXml = <<<'XML'
<?xml version="1.0" encoding="windows-1251"?>
<ValCurs Date="11.03.2026" name="Foreign Currency Market">
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

    public function test_currency_update_command_fetches_and_updates_rates(): void
    {
        Currency::create(['code' => 'RUB', 'name' => 'Rub', 'symbol' => '₽', 'is_base' => true, 'exchange_rate' => 1]);
        Currency::create(['code' => 'BYN', 'name' => 'Byn', 'symbol' => 'Br', 'is_base' => false, 'exchange_rate' => 1]);
        Currency::create(['code' => 'KZT', 'name' => 'Kzt', 'symbol' => '₸', 'is_base' => false, 'exchange_rate' => 1]);

        Http::fake([
            'www.cbr.ru/*' => Http::response($this->cbrXml, 200),
        ]);

        $this->artisan('currency:update')->assertSuccessful();

        $this->assertDatabaseHas('currencies', [
            'code' => 'BYN',
            'exchange_rate' => 28.5,
            'official_rate' => 28.5,
        ]);

        // 20 / 100 = 0.2
        $this->assertDatabaseHas('currencies', [
            'code' => 'KZT',
            'exchange_rate' => 0.2,
            'official_rate' => 0.2,
        ]);

        // Базовая валюта не изменяется
        $this->assertDatabaseHas('currencies', [
            'code' => 'RUB',
            'exchange_rate' => 1,
        ]);
    }

    public function test_update_rates_button_calls_command(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        Currency::create(['code' => 'RUB', 'name' => 'Rub', 'symbol' => '₽', 'is_base' => true, 'exchange_rate' => 1]);
        Currency::create(['code' => 'BYN', 'name' => 'Byn', 'symbol' => 'Br', 'is_base' => false, 'exchange_rate' => 1]);

        Http::fake([
            'www.cbr.ru/*' => Http::response($this->cbrXml, 200),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.currencies.update-rates'));

        $response->assertRedirect(route('admin.currencies.index'));
        $response->assertSessionHas('success');
    }
}
