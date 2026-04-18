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

    /**
     * partner.created не передаёт region — устанавливается дефолтный (min id).
     * Валюта определяется через регион пользователя.
     */
    public function test_partner_handler_sets_default_region_on_create(): void
    {
        $rub = Currency::factory()->create(['code' => 'RUB', 'is_base' => true]);
        $region = Region::factory()->create([
            'name' => 'Россия',
            'currency_id' => $rub->id,
        ]);

        $handler = new \App\Services\Erp\Handlers\HandlePartnerCreated;

        $email = 'partner-test-'.time().'@example.com';

        $payload = [
            'uuid' => 'test-uuid-'.time(),
            'name' => 'ООО Тест',
            'login' => $email,
            'email' => $email,
            'password' => bcrypt('TestPassword123'),
            'city' => 'Москва',
            'country' => 'RU',
            'phone' => '+79001234567',
            'is_active' => true,
        ];

        $handler->handle($payload);

        $user = User::where('erp_id', $payload['uuid'])->first();
        $this->assertNotNull($user, 'Пользователь должен быть создан');

        // region_id устанавливается дефолтным (min id) при создании из ERP
        $this->assertEquals(Region::min('id'), $user->region_id);
        // currency_id не устанавливается напрямую — только через регион
        $this->assertNull($user->currency_id ?? null);
    }

    /**
     * Если у пользователя уже есть region_id (установлен на сайте),
     * partner.created не перезаписывает его.
     */
    public function test_partner_handler_preserves_existing_region(): void
    {
        $byn = Currency::factory()->create(['code' => 'BYN', 'is_base' => false]);
        $region = Region::factory()->create([
            'name' => 'Беларусь',
            'currency_id' => $byn->id,
        ]);

        // Создаём пользователя заранее с привязанным регионом
        $email = 'byn-partner-'.time().'@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'region_id' => $region->id,
            'erp_id' => null,
        ]);

        $handler = new \App\Services\Erp\Handlers\HandlePartnerCreated;

        $payload = [
            'uuid' => 'test-byn-'.time(),
            'name' => 'ООО Бел-Тест',
            'login' => $email,
            'email' => $email,
            'city' => 'Минск',
            'country' => 'BY',
            'phone' => '+375291234567',
            'is_active' => true,
        ];

        $handler->handle($payload);

        $user->refresh();

        // erp_id привязан
        $this->assertEquals($payload['uuid'], $user->erp_id);
        // region_id сохранён — partner.created не трогает его
        $this->assertEquals($region->id, $user->region_id);
        // Валюта через регион
        $this->assertEquals('BYN', $user->region->currency->code);
    }
}
