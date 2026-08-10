<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Периодическая сверка статусов — страховка на случай потерянного вебхука.
 */
class ApiShipSyncStatusesTest extends DeliveryTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function intervalResponse(string $clientNumber, string $statusKey = 'onPointIn'): array
    {
        return [
            'rows' => [[
                'orderInfo' => [
                    'orderId' => '4561111',
                    'clientNumber' => $clientNumber,
                    'providerNumber' => 'CDEK-999',
                ],
                'status' => [
                    'key' => $statusKey,
                    'name' => 'Принят на складе отправления',
                    'created' => '2026-08-09T09:00:00+03:00',
                ],
            ]],
        ];
    }

    #[Test]
    #[TestDox('Сверка применяет статус, не пришедший вебхуком')]
    public function sync_applies_missed_status(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->fakeApiShip([
            '*/orders/statuses/interval*' => Http::response($this->intervalResponse($delivery->number), 200),
        ]);

        $this->artisan('apiship:sync-statuses')->assertSuccessful();

        $delivery->refresh();

        $this->assertSame('onPointIn', $delivery->apiship_status_key);
        $this->assertSame('in_transit', $delivery->status->value);
        $this->assertSame('CDEK-999', $delivery->provider_number);
        $this->assertSame('poll', $delivery->statusHistories()->first()->source);
    }

    #[Test]
    #[TestDox('Повторная сверка того же статуса ничего не меняет')]
    public function sync_is_idempotent(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->fakeApiShip([
            '*/orders/statuses/interval*' => Http::response($this->intervalResponse($delivery->number), 200),
        ]);

        $this->artisan('apiship:sync-statuses')->assertSuccessful();
        $this->artisan('apiship:sync-statuses')->assertSuccessful();

        $this->assertSame(1, $delivery->statusHistories()->count());
    }

    #[Test]
    #[TestDox('--dry-run ничего не сохраняет')]
    public function dry_run_changes_nothing(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->fakeApiShip([
            '*/orders/statuses/interval*' => Http::response($this->intervalResponse($delivery->number), 200),
        ]);

        $this->artisan('apiship:sync-statuses --dry-run')->assertSuccessful();

        $this->assertNull($delivery->fresh()->apiship_status_key);
        $this->assertSame(0, $delivery->statusHistories()->count());
    }

    #[Test]
    #[TestDox('При выключенной интеграции сверка не ходит в сеть')]
    public function disabled_integration_skips_sync(): void
    {
        config()->set('services.apiship.enabled', false);
        Http::fake();

        $this->artisan('apiship:sync-statuses')->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    #[TestDox('Ошибка ApiShip завершает команду ненулевым кодом')]
    public function api_failure_fails_command(): void
    {
        $this->fakeApiShip([
            '*/orders/statuses/interval*' => Http::response(['message' => 'Внутренняя ошибка'], 500),
        ]);

        $this->artisan('apiship:sync-statuses')->assertFailed();
    }

    #[Test]
    #[TestDox('Статусы по чужим заявкам пропускаются без ошибки')]
    public function unknown_orders_are_skipped(): void
    {
        $this->fakeApiShip([
            '*/orders/statuses/interval*' => Http::response($this->intervalResponse('DS-999999'), 200),
        ]);

        $this->artisan('apiship:sync-statuses')->assertSuccessful();

        $this->assertSame(0, \App\Models\Delivery\DeliveryShipmentStatusHistory::query()->count());
    }
}
