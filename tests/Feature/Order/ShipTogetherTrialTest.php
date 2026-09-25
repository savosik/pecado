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
use App\Services\Erp\Handlers\HandleOrderUpdated;
use App\Services\Order\ShipTogetherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.11.0, испытания совместной отгрузки (топик №8 Agent Hub): канарейка по партнёру,
 * команда reserve:ship-together-trial (пропуск сообщения, досылка, повтор теми же message_id)
 * и отказ группы по заказу, который 1С уже сняла с резерва руками.
 */
class ShipTogetherTrialTest extends TestCase
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
            'order_reserve.ship_together.canary' => '',
        ]);
        Queue::fake([PublishOrderToErpJob::class]);
        Storage::fake('local');

        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create(['external_id' => 'wh-trial-uuid']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id, 'warehouse_id' => $warehouse->id, 'type' => 'primary',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['region_id' => $region->id, 'reserve_allowed' => true, 'erp_id' => (string) Str::uuid()]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function reserveOrder(): Order
    {
        return Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'currency_code' => 'RUB',
            'delivery_method' => DeliveryMethod::PICKUP,
            'delivery_address' => null,
            'erp_number' => '29УТ-'.fake()->unique()->numberBetween(100000, 999999),
        ]);
    }

    #[Test]
    public function canary_limits_ship_together_to_listed_partners(): void
    {
        $other = User::factory()->create(['reserve_allowed' => true, 'erp_id' => (string) Str::uuid()]);

        $this->assertTrue(ShipTogetherService::enabledFor($this->user), 'пустая канарейка — доступно всем');

        config(['order_reserve.ship_together.canary' => $this->user->erp_id.', '.Str::uuid()]);
        $this->assertTrue(ShipTogetherService::enabledFor($this->user));
        $this->assertFalse(ShipTogetherService::enabledFor($other), 'партнёр вне списка не видит режим');

        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        $this->actingAs($other)->get('/cabinet/reserves')->assertOk()
            ->assertInertia(fn ($page) => $page->where('ship_together_enabled', false));
        $this->actingAs($this->user)->get('/cabinet/reserves')->assertOk()
            ->assertInertia(fn ($page) => $page->where('ship_together_enabled', true));

        $foreignA = Order::factory()->create(['user_id' => $other->id, 'reserve' => true, 'reserved_until' => now()->addHour(), 'status' => OrderStatus::READY_FOR_SHIPMENT]);
        $foreignB = Order::factory()->create(['user_id' => $other->id, 'reserve' => true, 'reserved_until' => now()->addHour(), 'status' => OrderStatus::READY_FOR_SHIPMENT]);
        $this->actingAs($other)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$foreignA->id, $foreignB->id]])
            ->assertNotFound();
        $this->actingAs($this->user)
            ->postJson('/cabinet/reserves/ship-together', ['order_ids' => [$a->id, $b->id]])
            ->assertOk();
    }

    #[Test]
    public function trial_send_with_skip_holds_one_message_then_resend_and_republish_reuse_message_ids(): void
    {
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        $c = $this->reserveOrder();

        $this->artisan('reserve:ship-together-trial', [
            'action' => 'send',
            '--user' => $this->user->erp_id,
            '--orders' => "{$a->erp_number},{$b->uuid},{$c->id}",
            '--skip' => $c->erp_number,
        ])->assertSuccessful();

        $key = $a->refresh()->ship_together_key;
        $this->assertNotNull($key);
        foreach ([$a, $b, $c] as $o) {
            $this->assertSame(ShipTogetherStatus::PENDING, $o->refresh()->ship_together_status, 'придержанный заказ тоже в ожидании — он в манифесте');
        }

        $sent = [];
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$sent) {
            $sent[] = $job->payload;

            return true;
        });
        $this->assertCount(2, $sent, 'сообщение по --skip придержано');
        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], array_column($sent, 'uuid'));
        foreach ($sent as $p) {
            $this->assertSame([$a->uuid, $b->uuid, $c->uuid], $p['ship_together_order_uuids'], 'манифест полный, включая придержанный');
        }

        $file = json_decode(Storage::disk('local')->get("ship-together-trials/{$key}.json"), true);
        $this->assertCount(3, $file['messages']);
        $held = collect($file['messages'])->firstWhere('uuid', $c->uuid);
        $this->assertFalse($held['sent']);

        // Досылка — только придержанное, тем же message_id
        Queue::fake([PublishOrderToErpJob::class]);
        $this->artisan('reserve:ship-together-trial', ['action' => 'resend', '--key' => $key])->assertSuccessful();
        Queue::assertPushed(PublishOrderToErpJob::class, 1);
        Queue::assertPushed(PublishOrderToErpJob::class, fn (PublishOrderToErpJob $job) => $job->payload['uuid'] === $c->uuid
            && $job->payload['message_id'] === $held['message_id']);

        // Повтор — все три, теми же message_id
        Queue::fake([PublishOrderToErpJob::class]);
        $this->artisan('reserve:ship-together-trial', ['action' => 'republish', '--key' => $key])->assertSuccessful();
        Queue::assertPushed(PublishOrderToErpJob::class, 3);
        $expected = array_column($file['messages'], 'message_id');
        Queue::assertPushed(PublishOrderToErpJob::class, fn (PublishOrderToErpJob $job) => in_array($job->payload['message_id'], $expected, true));

        $this->artisan('reserve:ship-together-trial', ['action' => 'status', '--key' => $key])->assertSuccessful();
    }

    #[Test]
    public function trial_send_refuses_when_partner_is_outside_canary(): void
    {
        config(['order_reserve.ship_together.canary' => (string) Str::uuid()]);
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();

        $this->artisan('reserve:ship-together-trial', [
            'action' => 'send', '--user' => (string) $this->user->id, '--orders' => "{$a->id},{$b->id}",
        ])->assertFailed();

        Queue::assertNothingPushed();
        $this->assertNull($a->refresh()->ship_together_status);
    }

    #[Test]
    public function conflict_outcome_keeps_reserve_false_for_order_released_by_erp_before_outcome(): void
    {
        $key = (string) Str::uuid();
        $a = $this->reserveOrder();
        $b = $this->reserveOrder();
        Order::query()->whereKey([$a->id, $b->id])->update([
            'ship_together_key' => $key, 'ship_together_status' => 'pending', 'ship_together_sent_at' => now(),
        ]);

        // Оператор 1С снял резерв у b до итога группы: reserve=false без полей группы
        app(HandleOrderUpdated::class)->handle(['event' => 'order.updated', 'uuid' => $b->uuid, 'reserve' => false]);
        $this->assertFalse($b->refresh()->reserve);
        $this->assertNull($b->ship_together_status, 'ожидание группы снято как одиночное подтверждение');

        // Итог группы: not_reserved, по b — с его фактическим reserve=false
        foreach ([[$a, true], [$b, false]] as [$order, $reserve]) {
            app(HandleOrderUpdated::class)->handle([
                'event' => 'order.updated', 'uuid' => $order->uuid, 'reserve' => $reserve,
                'ship_together_key' => $key, 'ship_together_status' => 'conflict',
                'ship_together_conflict' => ['reason' => 'not_reserved', 'message' => null, 'order_uuid' => $b->uuid],
            ]);
        }

        $this->assertTrue($a->refresh()->reserve, 'a снова в резерве');
        $this->assertSame(ShipTogetherStatus::CONFLICT, $a->ship_together_status);
        $this->assertFalse($b->refresh()->reserve, 'b остался снятым с резерва — reserve авторитетен');
        $this->assertSame(ShipTogetherStatus::CONFLICT, $b->ship_together_status);
        $this->assertSame('not_reserved', $b->ship_together_conflict['reason']);
    }
}
