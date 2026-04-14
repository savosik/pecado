<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandlePartnerCreatedCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_handler_creates_user_with_region_currency(): void
    {
        $rub = Currency::factory()->create(['code' => 'RUB', 'is_base' => true]);
        $region = Region::factory()->create([
            'name' => 'Россия',
            'currency_id' => $rub->id,
        ]);

        $handler = new \App\Services\Erp\Handlers\HandlePartnerCreated();

        $email = 'partner-test-' . time() . '@example.com';

        $payload = [
            'uuid' => 'test-uuid-' . time(),
            'name' => 'ООО Тест',
            'login' => $email,
            'email' => $email,
            'password' => bcrypt('TestPassword123'),
            'region' => 'Россия',
            'currency' => 'RUB',
            'city' => 'Москва',
            'country' => 'RU',
            'phone' => '+79001234567',
            'is_active' => true,
        ];

        $handler->handle($payload);

        $user = User::where('erp_id', $payload['uuid'])->first();
        $this->assertNotNull($user, 'Пользователь должен быть создан');

        // Валюта — через регион
        $this->assertEquals($region->id, $user->region_id);
        $this->assertEquals('RUB', $user->region->currency->code);
        // resolved_currency тоже работает
        $this->assertEquals('RUB', $user->resolved_currency->code);
    }

    public function test_partner_handler_does_not_set_currency_id_on_user(): void
    {
        $byn = Currency::factory()->create(['code' => 'BYN', 'is_base' => false]);
        $region = Region::factory()->create([
            'name' => 'Беларусь',
            'currency_id' => $byn->id,
        ]);

        $handler = new \App\Services\Erp\Handlers\HandlePartnerCreated();

        $email = 'byn-partner-' . time() . '@example.com';

        $payload = [
            'uuid' => 'test-byn-' . time(),
            'name' => 'ООО Бел-Тест',
            'login' => $email,
            'email' => $email,
            'password' => bcrypt('TestPassword123'),
            'region' => 'Беларусь',
            'currency' => 'BYN',
            'city' => 'Минск',
            'country' => 'BY',
            'phone' => '+375291234567',
            'is_active' => true,
        ];

        $handler->handle($payload);

        $user = User::where('erp_id', $payload['uuid'])->first();
        $this->assertNotNull($user, 'Пользователь должен быть создан');

        // Прямого currency_id на пользователе нет — валюта через регион
        $this->assertNull($user->currency_id ?? null);
        $this->assertEquals('BYN', $user->region->currency->code);
    }
}
