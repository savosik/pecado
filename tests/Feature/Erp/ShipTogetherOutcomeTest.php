<?php

namespace Tests\Feature\Erp;

use App\Enums\OrderStatus;
use App\Enums\ShipTogetherStatus;
use App\Models\CrmEmail;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.11.0, совместная отгрузка: итог группы из 1С приходит в order.updated
 * (ship_together_status + ship_together_conflict). Успех снимает резерв по всей
 * группе, отказ возвращает заказы в окно резерва и шлёт одно письмо на группу.
 * Плюс валидация новых полей по JSON Schema с обеих сторон и страховочный тайм-аут.
 */
class ShipTogetherOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true, 'order_reserve.ship_together.enabled' => true, 'mail_stream.enabled' => true]);

        $manager = User::factory()->create();
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $this->user = User::factory()->create(['personal_manager_id' => $profile->id, 'reserve_allowed' => true]);
    }

    /** @return array{0: string, 1: Order, 2: Order} */
    private function pendingGroup(): array
    {
        $key = (string) Str::uuid();
        $orders = [];
        foreach (['ЗК-1001', 'ЗК-1002'] as $number) {
            $orders[] = Order::factory()->create([
                'user_id' => $this->user->id,
                'status' => OrderStatus::READY_FOR_SHIPMENT,
                'erp_number' => $number,
                'reserve' => true,
                'reserved_until' => now()->addHours(20),
                'ship_together_key' => $key,
                'ship_together_status' => ShipTogetherStatus::PENDING,
                'ship_together_sent_at' => now(),
            ]);
        }

        return [$key, $orders[0], $orders[1]];
    }

    #[Test]
    public function confirmed_outcome_releases_reserve_for_each_order_of_the_group(): void
    {
        [$key, $a, $b] = $this->pendingGroup();

        foreach ([$a, $b] as $order) {
            app(HandleOrderUpdated::class)->handle([
                'event' => 'order.updated',
                'uuid' => $order->uuid,
                'status' => 'ready_for_shipment',
                'reserve' => false,
                'ship_together_key' => $key,
                'ship_together_status' => 'confirmed',
                'ship_together_conflict' => null,
            ]);
        }

        foreach ([$a, $b] as $order) {
            $order->refresh();
            $this->assertFalse($order->reserve);
            $this->assertNull($order->reserved_until);
            $this->assertSame(ShipTogetherStatus::CONFIRMED, $order->ship_together_status);
            $this->assertSame('confirmed', $order->reserve_outcome);
            $this->assertNull($order->ship_together_conflict);
        }
        $this->assertDatabaseMissing('crm_emails', ['origin_event' => 'orders.ship_together_rejected']);
    }

    #[Test]
    public function conflict_outcome_returns_orders_to_reserve_and_sends_one_letter_per_group(): void
    {
        [$key, $a, $b] = $this->pendingGroup();

        foreach ([$a, $b] as $order) {
            app(HandleOrderUpdated::class)->handle([
                'event' => 'order.updated',
                'uuid' => $order->uuid,
                'reserve' => true,
                'reserved_until' => now()->addHours(19)->toIso8601String(),
                'ship_together_key' => $key,
                'ship_together_status' => 'conflict',
                'ship_together_conflict' => [
                    'reason' => 'shortage',
                    'message' => 'По заказу ЗК-1002 не хватило 3 шт.',
                    'order_uuid' => $b->uuid,
                ],
            ]);
        }

        foreach ([$a, $b] as $order) {
            $order->refresh();
            $this->assertTrue($order->reserve, 'отказ по всей группе — заказ снова в резерве');
            $this->assertSame(ShipTogetherStatus::CONFLICT, $order->ship_together_status);
            $this->assertNull($order->reserve_outcome);
            $this->assertSame('shortage', $order->ship_together_conflict['reason']);
            $this->assertSame($b->uuid, $order->ship_together_conflict['order_uuid']);
            $this->assertTrue($order->cancellableByClient(), 'после отказа отмена и правки снова открыты');
        }

        $letters = CrmEmail::query()->where('origin_event', 'orders.ship_together_rejected')->get();
        $this->assertCount(1, $letters, 'одно письмо на группу, а не на заказ');
        $this->assertStringContainsString('ЗК-1001, ЗК-1002', $letters->first()->subject);
    }

    #[Test]
    public function reserve_false_without_group_fields_clears_pending_as_single_confirmation(): void
    {
        [, $a] = $this->pendingGroup();

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $a->uuid,
            'reserve' => false,
        ]);

        $a->refresh();
        $this->assertFalse($a->reserve);
        $this->assertNull($a->ship_together_status, 'старая 1С или ручной перевод — ожидание группы снято');
        $this->assertSame('confirmed', $a->reserve_outcome);
    }

    #[Test]
    public function incoming_schema_accepts_outcome_and_rejects_unknown_reason(): void
    {
        $validator = app(ErpMessageValidator::class);
        $base = [
            'event' => 'order.updated',
            'message_id' => 'msg-1',
            'uuid' => (string) Str::uuid(),
            'reserve' => true,
            'ship_together_key' => (string) Str::uuid(),
            'ship_together_status' => 'conflict',
        ];

        $ok = $validator->validate('order.updated', $base + ['ship_together_conflict' => ['reason' => 'timeout', 'message' => null, 'order_uuid' => null]]);
        $this->assertTrue($ok['valid'], implode('; ', $ok['errors']));

        $bad = $validator->validate('order.updated', $base + ['ship_together_conflict' => ['reason' => 'because']]);
        $this->assertFalse($bad['valid'], 'код причины вне перечисления не проходит схему');

        $badStatus = $validator->validate('order.updated', ['ship_together_status' => 'collecting'] + $base);
        $this->assertFalse($badStatus['valid'], 'промежуточное состояние группы наружу не публикуется');
    }

    #[Test]
    public function outbound_schema_requires_key_and_manifest_together(): void
    {
        $validator = app(ErpMessageValidator::class);
        $uuid = (string) Str::uuid();
        $base = [
            'event' => 'order.confirmed',
            'message_id' => 'msg-2',
            'uuid' => $uuid,
            'confirmed_at' => now()->toIso8601String(),
        ];

        $this->assertTrue($validator->validateOutbound('order.confirmed', $base)['valid'], 'без полей — прежнее одиночное подтверждение');

        $onlyKey = $validator->validateOutbound('order.confirmed', $base + ['ship_together_key' => (string) Str::uuid()]);
        $this->assertFalse($onlyKey['valid'], 'ключ без манифеста не проходит');

        $short = $validator->validateOutbound('order.confirmed', $base + [
            'ship_together_key' => (string) Str::uuid(),
            'ship_together_order_uuids' => [$uuid],
        ]);
        $this->assertFalse($short['valid'], 'манифест из одного заказа не проходит');

        $dup = $validator->validateOutbound('order.confirmed', $base + [
            'ship_together_key' => (string) Str::uuid(),
            'ship_together_order_uuids' => [$uuid, $uuid],
        ]);
        $this->assertFalse($dup['valid'], 'повторы в манифесте не проходят');
    }

    #[Test]
    public function stale_pending_group_is_returned_to_reserve_by_timeout_command(): void
    {
        config(['order_reserve.ship_together.pending_timeout_minutes' => 60]);
        [, $a, $b] = $this->pendingGroup();
        Order::query()->whereKey([$a->id, $b->id])->update(['ship_together_sent_at' => now()->subMinutes(90)]);
        $fresh = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'ship_together_key' => (string) Str::uuid(),
            'ship_together_status' => ShipTogetherStatus::PENDING,
            'ship_together_sent_at' => now()->subMinutes(10),
        ]);

        $this->artisan('reserve:ship-together-timeout')->assertSuccessful();

        foreach ([$a, $b] as $order) {
            $order->refresh();
            $this->assertTrue($order->reserve);
            $this->assertSame(ShipTogetherStatus::CONFLICT, $order->ship_together_status);
            $this->assertSame('no_response', $order->ship_together_conflict['reason']);
        }
        $this->assertSame(ShipTogetherStatus::PENDING, $fresh->refresh()->ship_together_status, 'свежая группа ещё ждёт');
    }
}
