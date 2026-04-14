<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencySwitchRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_switch_route_does_not_exist(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api/currency/switch', [
            'currency_code' => 'BYN',
        ]);

        // Маршрут удалён — должен быть 404 или 405
        $this->assertTrue(
            in_array($response->getStatusCode(), [404, 405]),
            "POST /api/currency/switch должен вернуть 404 или 405, получен: {$response->getStatusCode()}"
        );
    }

    public function test_inertia_shared_props_no_available_currencies(): void
    {
        $rub = Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'name' => 'Российский рубль', 'symbol' => '₽']);
        $region = Region::factory()->create(['currency_id' => $rub->id]);
        $user = User::factory()->create(['region_id' => $region->id]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();

        // Проверяем, что в shared props нет ключа 'available'
        $response->assertInertia(fn ($page) => $page
            ->has('currency')
            ->where('currency.code', 'RUB')
            ->where('currency.symbol', '₽')
            ->missing('currency.available')
        );
    }
}
