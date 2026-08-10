<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Приём вебхука ORDER_STATUS.
 *
 * Ключевое требование ApiShip: неудачей считается HTTP ≥ 500, после трёх таких
 * попыток событие теряется навсегда. Поэтому на всё, кроме проблем доступа,
 * эндпоинт обязан отвечать 200 — это проверяется отдельными сценариями.
 */
class ApiShipWebhookTest extends DeliveryTestCase
{
    private const SECRET = 'super-secret-webhook-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.apiship.webhook.enabled', true);
        config()->set('services.apiship.webhook.secret', self::SECRET);
        config()->set('services.apiship.webhook.allowed_ips', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendWebhook(array $payload, string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/delivery/apiship/webhook/{$secret}", $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $clientNumber, string $statusKey = 'onWay'): array
    {
        return [
            'orderInfo' => [
                'orderId' => '4561111',
                'clientNumber' => $clientNumber,
                'providerNumber' => 'CDEK-1234567890',
                'barcode' => 'BC-777',
                'trackingUrl' => 'https://cdek.ru/track/1234567890',
            ],
            'status' => [
                'key' => $statusKey,
                'name' => 'В пути',
                'created' => '2026-08-09T12:19:44+03:00',
                'providerCode' => '3',
            ],
        ];
    }

    #[Test]
    #[TestDox('Вебхук обновляет статус, трек-номер и пишет запись в журнал')]
    public function webhook_applies_status_and_tracking(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->sendWebhook($this->payload($delivery->number))
            ->assertOk()
            ->assertJson(['status' => 'applied']);

        $delivery->refresh();

        $this->assertSame('onWay', $delivery->apiship_status_key);
        $this->assertSame('in_transit', $delivery->status->value);
        $this->assertSame('CDEK-1234567890', $delivery->provider_number);
        $this->assertSame('https://cdek.ru/track/1234567890', $delivery->tracking_url);
        $this->assertSame(1, $delivery->statusHistories()->count());
    }

    #[Test]
    #[TestDox('Повторный вебхук с тем же статусом историю не удлиняет')]
    public function repeated_status_does_not_duplicate_history(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->sendWebhook($this->payload($delivery->number))->assertOk();
        $this->sendWebhook($this->payload($delivery->number))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, $delivery->statusHistories()->count());
    }

    #[Test]
    #[TestDox('Статус «Доставлен» закрывает отправку')]
    public function delivered_status_closes_shipment(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->sendWebhook($this->payload($delivery->number, 'delivered'))->assertOk();

        $this->assertSame('delivered', $delivery->fresh()->status->value);
    }

    #[Test]
    #[TestDox('Незнакомый ключ статуса сохраняется, но внутренний статус не ломает')]
    public function unknown_status_key_is_stored_as_is(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->sendWebhook($this->payload($delivery->number, 'someNewStatusFromProvider'))->assertOk();

        $delivery->refresh();

        $this->assertSame('someNewStatusFromProvider', $delivery->apiship_status_key);
        $this->assertSame('submitted', $delivery->status->value);
    }

    #[Test]
    #[TestDox('Неизвестный номер отправки не заставляет ApiShip ретраить')]
    public function unknown_client_number_returns_ok(): void
    {
        $this->sendWebhook($this->payload('DS-999999'))
            ->assertOk()
            ->assertJson(['status' => 'unknown']);
    }

    #[Test]
    #[TestDox('Payload без orderInfo игнорируется с кодом 200')]
    public function malformed_payload_returns_ok(): void
    {
        $this->sendWebhook(['nonsense' => true])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    #[Test]
    #[TestDox('Неверный секрет в URL — 403')]
    public function wrong_secret_is_rejected(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->sendWebhook($this->payload($delivery->number), 'wrong-secret')
            ->assertForbidden();

        $this->assertNull($delivery->fresh()->apiship_status_key);
    }

    #[Test]
    #[TestDox('Выключенный вебхук отвечает 503')]
    public function disabled_webhook_returns_503(): void
    {
        config()->set('services.apiship.webhook.enabled', false);

        $this->sendWebhook($this->payload('DS-000001'))->assertStatus(503);
    }

    #[Test]
    #[TestDox('Включённый вебхук без секрета в конфиге не работает')]
    public function webhook_without_configured_secret_is_closed(): void
    {
        config()->set('services.apiship.webhook.secret', '');

        $this->sendWebhook($this->payload('DS-000001'), 'anything')->assertStatus(503);
    }

    #[Test]
    #[TestDox('Запрос с IP вне allowlist отклоняется')]
    public function ip_allowlist_is_enforced(): void
    {
        config()->set('services.apiship.webhook.allowed_ips', ['203.0.113.7']);

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->sendWebhook($this->payload($delivery->number))->assertForbidden();
    }
}
