<?php

namespace Tests\Feature\Order;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\ShipTogetherStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\ErpMessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.11.0, совместная отгрузка резервов (топик №7 Agent Hub): клиент отмечает
 * несколько резервов и отправляет их в отгрузку вместе. Сайт проверяет группу,
 * помечает заказы «ждём склад», шлёт по каждому order.confirmed с одинаковым
 * ключом и манифестом и НЕ снимает резерв до итога из 1С.
 */
class ShipTogetherConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'order_reserve.enabled' => true,
            'order_reserve.ship_together.enabled' => true,
        ]);
        Queue::fake([PublishOrderToErpJob::class]);

        $region = Region::factory()->create(['name' => 'Тестовый регион']);
        $warehouse = Warehouse::factory()->create(['name' => 'Основной', 'external_id' => 'wh-main-uuid']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create(['region_id' => $region->id, 'reserve_allowed' => true]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function reserveOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'currency_code' => 'RUB',
            'delivery_method' => DeliveryMethod::PICKUP,
            'delivery_address' => null,
        ], $attrs));
    }

    #[Test]
    public function group_publishes_order_confirmed_with_same_key_and_manifest_and_keeps_reserve(): void
    {
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        $c = $this->reserveOrder();

        $response = $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id, $c->id]])
            ->assertOk()
            ->assertJsonStructure(['message', 'ship_together_key']);

        $key = $response->json('ship_together_key');
        $expectedManifest = [$a->uuid, $b->uuid, $c->uuid];

        foreach ([$a, $b, $c] as $order) {
            $order->refresh();
            $this->assertTrue($order->reserve, 'резерв держится локально до итога 1С');
            $this->assertSame(ShipTogetherStatus::PENDING, $order->ship_together_status);
            $this->assertSame($key, $order->ship_together_key);
            $this->assertNotNull($order->ship_together_sent_at);
        }

        $payloads = [];
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$payloads) {
            $payloads[] = $job->payload;

            return true;
        });
        $this->assertCount(3, $payloads, 'по одному order.confirmed на заказ группы');

        $validator = app(ErpMessageValidator::class);
        foreach ($payloads as $payload) {
            $this->assertSame('order.confirmed', $payload['event']);
            $this->assertSame($key, $payload['ship_together_key']);
            $this->assertSame($expectedManifest, $payload['ship_together_order_uuids'], 'манифест одинаков и в одном порядке');
            $this->assertContains($payload['uuid'], $payload['ship_together_order_uuids']);
            $this->assertArrayNotHasKey('ship_together_size', $payload);
            $validation = $validator->validateOutbound('order.confirmed', $payload);
            $this->assertTrue($validation['valid'], implode('; ', $validation['errors']));
        }
    }

    #[Test]
    public function pending_group_locks_confirm_edit_and_cancel_until_outcome(): void
    {
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id]])
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson("/cabinet/orders/{$a->id}/confirm-reserve")
            ->assertStatus(409);
        $this->actingAs($this->user)
            ->postJson("/cabinet/orders/{$a->id}/reserve-items", ['base_items_version' => 0, 'items' => [['id' => 1, 'quantity' => 1]]])
            ->assertStatus(409);
        $this->actingAs($this->user)
            ->postJson("/cabinet/orders/{$a->id}/cancel")
            ->assertStatus(422);
        // повторная группа с тем же заказом тоже закрыта
        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ship_together_pending');

        $this->assertFalse($a->refresh()->cancellableByClient());
        $this->assertTrue($a->reserve);
    }

    #[Test]
    public function single_confirm_after_group_conflict_clears_stale_group_state(): void
    {
        $a = $this->reserveOrder();
        $a->forceFill([
            'ship_together_key' => (string) \Illuminate\Support\Str::uuid(),
            'ship_together_status' => ShipTogetherStatus::CONFLICT,
            'ship_together_conflict' => ['reason' => 'incompatible', 'message' => null, 'order_uuid' => null],
        ])->save();

        $this->actingAs($this->user)
            ->postJson("/cabinet/orders/{$a->id}/confirm-reserve")
            ->assertOk();

        $a->refresh();
        $this->assertFalse($a->reserve);
        $this->assertNull($a->ship_together_status, 'старый отказ группы на карточке снят (S3)');
        $this->assertNull($a->ship_together_conflict);
    }

    #[Test]
    public function group_requires_at_least_two_orders(): void
    {
        $a = $this->reserveOrder();

        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id]])
            ->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertNull($a->refresh()->ship_together_status);
    }

    #[Test]
    public function group_rejects_incompatible_orders_before_publishing(): void
    {
        $base = $this->reserveOrder();

        $otherCompany = Company::factory()->create(['user_id' => $this->user->id]);
        $cases = [
            'group_mixed_company' => $this->reserveOrder(['company_id' => $otherCompany->id]),
            'group_mixed_currency' => $this->reserveOrder(['currency_code' => 'USD']),
            'group_mixed_delivery' => $this->reserveOrder(['delivery_method' => DeliveryMethod::DELIVERY, 'delivery_address' => 'Москва, Тверская 1']),
            'not_reserved' => $this->reserveOrder(['reserve' => false]),
        ];

        foreach ($cases as $code => $other) {
            $this->actingAs($this->user)
                ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$base->id, $other->id]])
                ->assertStatus(422)
                ->assertJsonPath('code', $code);
        }

        $d1 = $this->reserveOrder(['delivery_method' => DeliveryMethod::DELIVERY, 'delivery_address' => 'Москва, Тверская 1']);
        $d2 = $this->reserveOrder(['delivery_method' => DeliveryMethod::DELIVERY, 'delivery_address' => 'Москва, Арбат 2']);
        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$d1->id, $d2->id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'group_mixed_address');

        Queue::assertNothingPushed();
        $this->assertNull($base->refresh()->ship_together_status, 'отказ до публикации ничего не помечает');
    }

    #[Test]
    public function group_requires_exactly_one_warehouse(): void
    {
        // Второй основной склад региона → заказ комплектуется с двух складов
        $second = Warehouse::factory()->create(['name' => 'Второй', 'external_id' => 'wh-second-uuid']);
        DB::table('region_warehouse')->insert([
            'region_id' => $this->user->region_id,
            'warehouse_id' => $second->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();

        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'group_multi_warehouse');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function foreign_orders_are_not_found(): void
    {
        $a = $this->reserveOrder();
        $foreign = Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
        ]);

        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $foreign->id]])
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function endpoint_is_hidden_behind_ship_together_flag(): void
    {
        config(['order_reserve.ship_together.enabled' => false]);
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();

        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id]])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->get('/cabinet/reserves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ship_together_enabled', false));
    }

    #[Test]
    public function reserves_index_lists_recently_shipped_groups_and_singles(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();
        $a = $this->reserveOrder(['reserve' => false, 'reserve_outcome' => 'confirmed', 'ship_together_key' => $key, 'ship_together_status' => ShipTogetherStatus::CONFIRMED, 'ship_together_sent_at' => now()->subMinutes(5), 'erp_number' => '29УТ-1']);
        $b = $this->reserveOrder(['reserve' => false, 'reserve_outcome' => 'confirmed', 'ship_together_key' => $key, 'ship_together_status' => ShipTogetherStatus::CONFIRMED, 'ship_together_sent_at' => now()->subMinutes(5), 'erp_number' => '29УТ-2']);
        $single = $this->reserveOrder(['reserve' => false, 'reserve_outcome' => 'confirmed', 'erp_number' => '29УТ-3']);
        $this->reserveOrder(['reserve' => false, 'reserve_outcome' => 'cancelled']); // отменённый — не показываем
        Order::query()->whereKey($this->reserveOrder(['reserve' => false, 'reserve_outcome' => 'confirmed'])->id)
            ->update(['updated_at' => now()->subDays(3)]); // старый — не показываем

        $this->actingAs($this->user)
            ->get('/cabinet/reserves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('recent_shipments', 2)
                ->where('recent_shipments.0.together', fn ($v) => is_bool($v))
                ->where('recent_shipments', function ($items) use ($key, $a, $b, $single) {
                    $items = collect($items);
                    $group = $items->firstWhere('key', $key);
                    $one = $items->firstWhere('key', null);

                    return $group && $group['together'] === true
                        && collect($group['order_ids'])->sort()->values()->all() === [$a->id, $b->id]
                        && $group['numbers'] === ['29УТ-1', '29УТ-2']
                        && $one && $one['together'] === false && $one['order_ids'] === [$single->id];
                }));
    }

    #[Test]
    public function reserves_index_exposes_group_state(): void
    {
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id]])
            ->assertOk();

        $this->actingAs($this->user)
            ->get('/cabinet/reserves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ship_together_enabled', true)
                ->has('reserves', 2)
                ->where('reserves.0.ship_together.status', 'pending')
                ->where('reserves.0.ship_together.status_label', 'Ждём подтверждения склада'));
    }
}
