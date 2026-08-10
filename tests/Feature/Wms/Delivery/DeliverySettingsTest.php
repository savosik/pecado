<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Setting;
use App\Services\Delivery\ApiShipSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Настройки ApiShip через интерфейс.
 *
 * Ключевое требование: значения из формы перекрывают `.env` — иначе экранная
 * форма ничего бы не меняла, а склад лезть в переменные окружения не может.
 */
class DeliverySettingsTest extends DeliveryTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'base_url' => 'https://api.apiship.ru/v1',
            'timeout' => 30,
            'default_item_weight_grams' => 500,
            'default_place_length' => 40,
            'default_place_width' => 30,
            'default_place_height' => 20,
        ], $overrides);
    }

    #[Test]
    #[TestDox('Кладовщика к настройкам не пускают')]
    public function storekeeper_cannot_open_settings(): void
    {
        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/delivery-settings')
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('Начальник склада открывает настройки')]
    public function warehouse_head_can_open_settings(): void
    {
        $this->actingAs($this->userWithRole('warehouse-head'))
            ->get('/wms/delivery-settings')
            ->assertOk();
    }

    #[Test]
    #[TestDox('Токен сохраняется зашифрованным и перекрывает .env')]
    public function token_is_encrypted_and_overrides_env(): void
    {
        config()->set('services.apiship.token', 'token-from-env');

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->put('/wms/delivery-settings', $this->payload(['token' => 'token-from-ui']))
            ->assertRedirect();

        // Через DB, а не Eloquent: аксессор Setting::value кастует значение,
        // а проверяем мы именно то, что физически лежит в колонке.
        $stored = \Illuminate\Support\Facades\DB::table('settings')
            ->where('group', 'apiship')->where('key', 'token')->value('value');

        $this->assertNotSame('token-from-ui', $stored, 'Токен лежит в базе открытым текстом');
        $this->assertSame('token-from-ui', Crypt::decryptString($stored));
        $this->assertSame('token-from-ui', app(ApiShipSettings::class)->string('token'));
    }

    #[Test]
    #[TestDox('Пустое поле секрета не затирает сохранённое значение')]
    public function empty_secret_does_not_wipe_stored_value(): void
    {
        $head = $this->userWithRole('warehouse-head');

        $this->actingAs($head)->put('/wms/delivery-settings', $this->payload(['token' => 'first-token']));
        $this->actingAs($head)->put('/wms/delivery-settings', $this->payload(['token' => '']));

        $this->assertSame('first-token', app(ApiShipSettings::class)->string('token'));
    }

    #[Test]
    #[TestDox('Флаг «стереть» убирает секрет и возвращает значение из .env')]
    public function clear_flag_wipes_the_secret(): void
    {
        config()->set('services.apiship.token', 'token-from-env');
        $head = $this->userWithRole('warehouse-head');

        $this->actingAs($head)->put('/wms/delivery-settings', $this->payload(['token' => 'token-from-ui']));
        $this->actingAs($head)->put('/wms/delivery-settings', $this->payload(['clear_token' => true]));

        $this->assertSame('token-from-env', app(ApiShipSettings::class)->string('token'));
    }

    #[Test]
    #[TestDox('Секреты не уезжают на фронт — только признак «задано»')]
    public function secrets_are_never_sent_to_the_browser(): void
    {
        $head = $this->userWithRole('warehouse-head');
        $this->actingAs($head)->put('/wms/delivery-settings', $this->payload(['token' => 'super-secret-token']));

        $props = $this->inertiaProps('/wms/delivery-settings');

        $this->assertSame('', $props['settings']['token']);
        $this->assertTrue($props['settings']['token_is_set']);
        $this->assertStringNotContainsString('super-secret-token', json_encode($props, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    #[TestDox('Адрес отправителя из настроек уезжает в заявку перевозчику')]
    public function sender_address_from_settings_reaches_the_carrier(): void
    {
        $this->actingAs($this->userWithRole('warehouse-head'))
            ->put('/wms/delivery-settings', $this->payload([
                'token' => 'ui-token',
                'sender_city' => 'Екатеринбург',
                'sender_phone' => '+79990000000',
                'sender_street' => 'ул Новая',
                'sender_house' => '7',
            ]));

        $this->fakeApiShip([
            '*/v1/orders' => Http::response(['orderId' => '777'], 200),
        ]);

        $delivery = \App\Models\Delivery\DeliveryShipment::factory()->calculated()->create();
        $this->addPlace($delivery);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit")
            ->assertSessionHas('success');

        $sent = $this->sentPayload('/v1/orders');

        $this->assertSame('Екатеринбург', $sent['sender']['city']);
        $this->assertSame('ул Новая', $sent['sender']['street']);
        // Токен из формы означает, что POST /login не понадобился.
        $this->assertNull($this->sentPayload('/login'));
    }

    #[Test]
    #[TestDox('Некорректные значения не сохраняются')]
    public function validation_rejects_bad_values(): void
    {
        $this->actingAs($this->userWithRole('warehouse-head'))
            ->put('/wms/delivery-settings', $this->payload([
                'base_url' => 'не ссылка',
                'timeout' => 500,
                'default_item_weight_grams' => 0,
                'webhook_secret' => 'коротко',
            ]))
            ->assertSessionHasErrors(['base_url', 'timeout', 'default_item_weight_grams', 'webhook_secret']);

        $this->assertSame(0, Setting::query()->where('group', 'apiship')->count());
    }

    #[Test]
    #[TestDox('Проверка связи показывает ответ ApiShip')]
    public function test_connection_reports_result(): void
    {
        $this->actingAs($this->userWithRole('warehouse-head'))
            ->put('/wms/delivery-settings', $this->payload(['token' => 'ui-token']));

        $this->fakeApiShip([
            '*/webhooks' => Http::response([], 200),
            '*/lists/points*' => Http::response(['rows' => []], 200),
        ]);

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->post('/wms/delivery-settings/test')
            ->assertSessionHas('success');
    }
}
