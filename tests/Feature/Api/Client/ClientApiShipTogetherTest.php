<?php

namespace Tests\Feature\Api\Client;

use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * v16.11.0: операция reserves.confirm_group клиентского API — совместная отгрузка
 * группы резервов. Та же логика, что в кабинете: ключ и манифест в order.confirmed,
 * резерв держится до итога 1С, одиночные действия по заказам группы закрыты.
 */
class ClientApiShipTogetherTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true, 'order_reserve.canary' => '', 'order_reserve.ship_together.enabled' => true]);
        Queue::fake([PublishOrderToErpJob::class]);

        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create(['external_id' => 'wh-api-uuid']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id, 'warehouse_id' => $warehouse->id, 'type' => 'primary',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->client->update(['region_id' => $region->id, 'reserve_allowed' => true]);
    }

    private function reserveOrder(): Order
    {
        return Order::factory()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'currency_code' => 'RUB',
            'delivery_method' => 'pickup',
            'delivery_address' => null,
        ]);
    }

    #[Test]
    #[TestDox('reserves.confirm_group: order.confirmed с общим ключом и манифестом, резерв держится, одиночные действия закрыты')]
    public function confirm_group_sends_order_confirmed_with_shared_key_and_keeps_reserve(): void
    {
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();

        $response = $this->api('POST', '/reserves/ship-together', ['orders' => [(string) $a->id, $b->uuid]])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $key = $response->json('data.ship_together_key');
        $this->assertTrue($a->refresh()->reserve, 'резерв держится до итога 1С');
        $this->assertSame('pending', $a->ship_together_status->value);

        $pushed = [];
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$pushed) {
            $pushed[] = $job->payload;

            return true;
        });
        $this->assertCount(2, $pushed);
        foreach ($pushed as $payload) {
            $this->assertSame('order.confirmed', $payload['event']);
            $this->assertSame($key, $payload['ship_together_key']);
            $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], $payload['ship_together_order_uuids']);
        }

        $this->api('POST', "/reserves/{$a->id}/confirm")->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'ship_together_pending');

        $this->api('GET', '/reserves')->assertOk()
            ->assertJsonPath('data.0.ship_together.status', 'pending');
    }

    #[Test]
    #[TestDox('reserves.confirm_group: несовместимая группа отклоняется кодом до отправки в шину')]
    public function incompatible_group_is_rejected_with_code(): void
    {
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        $b->update(['currency_code' => 'USD']);

        $this->api('POST', '/reserves/ship-together', ['orders' => [(string) $a->id, (string) $b->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'group_mixed_currency');

        Queue::assertNothingPushed();
        $this->api('POST', '/reserves/ship-together', ['orders' => [(string) $a->id]])
            ->assertStatus(422);
    }
}
