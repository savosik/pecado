<?php

namespace Tests\Feature\Wms\Delivery;

use App\Services\Delivery\ApiShipSettings;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Регистрация подписки на ORDER_STATUS.
 *
 * Тело запроса проверяется по полям: ApiShip требует `type`, а не `eventType`,
 * и на неверное имя отвечает обычной ошибкой валидации. Без этого теста промах
 * в названии поля обнаруживается только руками против боевого API — что один раз
 * уже и случилось.
 */
class ApiShipRegisterWebhookTest extends DeliveryTestCase
{
    private function configure(string $secret = 'webhook-secret-value'): void
    {
        app(ApiShipSettings::class)->save([
            'enabled' => true,
            'token' => 'test-token',
            'webhook_enabled' => true,
            'webhook_secret' => $secret,
        ]);
    }

    #[Test]
    #[TestDox('Подписка уходит с полем type и адресом, содержащим секрет')]
    public function subscription_is_sent_with_type_field(): void
    {
        $this->fakeApiShip([
            '*/webhooks' => Http::response(['uuid' => '3c4f3342-e3f5-4760-996a-e3f1660c3a21'], 200),
        ]);

        $this->configure();

        $this->artisan('apiship:register-webhook')->assertSuccessful();

        $sent = $this->sentPayload('/webhooks');

        $this->assertSame('ORDER_STATUS', $sent['type'] ?? null);
        $this->assertArrayNotHasKey('eventType', $sent);
        $this->assertStringContainsString(
            '/api/delivery/apiship/webhook/webhook-secret-value',
            $sent['url'],
        );
    }

    #[Test]
    #[TestDox('Без секрета подписка не создаётся')]
    public function subscription_requires_a_secret(): void
    {
        $this->fakeApiShip();

        app(ApiShipSettings::class)->save(['enabled' => true, 'token' => 'test-token']);
        config()->set('services.apiship.webhook.secret', '');

        $this->artisan('apiship:register-webhook')->assertFailed();

        $this->assertNull($this->sentPayload('/webhooks'));
    }

    #[Test]
    #[TestDox('Ошибка ApiShip доносится текстом и не выдаёт успех')]
    public function api_error_is_reported(): void
    {
        $this->fakeApiShip([
            '*/webhooks' => Http::response([
                'code' => '040000',
                'message' => 'Ошибка валидации',
                'errors' => [['field' => 'type', 'message' => 'Значение «Type» неверно.']],
            ], 400),
        ]);

        $this->configure();

        $this->artisan('apiship:register-webhook')->assertFailed();
    }

    #[Test]
    #[TestDox('Список подписок читается и когда ApiShip отдаёт голый массив')]
    public function listing_handles_a_bare_array(): void
    {
        // GET /webhooks отвечает массивом без обёртки `rows` — в отличие от
        // остальных списков ApiShip.
        $this->fakeApiShip([
            '*/webhooks' => Http::response([
                ['uuid' => 'abc', 'type' => 'ORDER_STATUS', 'url' => 'https://pecado.ru/api/delivery/apiship/webhook/xxx'],
            ], 200),
        ]);

        $this->configure();

        $this->artisan('apiship:register-webhook --list')
            ->expectsOutputToContain('ORDER_STATUS')
            ->assertSuccessful();
    }
}
