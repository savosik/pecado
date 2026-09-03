<?php

namespace Tests\Feature\Erp;

use App\Models\Order;
use App\Models\OrderReserveOverride;
use App\Models\User;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use App\Services\Erp\Handlers\HandlePartnerUpdated;
use App\Services\Order\ReservePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-05): поля резерва принимаются из 1С
 * и сохраняются; ReservePolicy определяет охват и срок.
 */
class OrderReserveModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_updated_saves_reserve_fields_from_erp(): void
    {
        $order = Order::factory()->create();

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'reserve' => true,
            'reserved_until' => '2026-09-04T15:00:00+03:00',
            'items_version' => 5,
        ]);

        $order->refresh();

        $this->assertTrue($order->reserve);
        $this->assertSame(5, $order->items_version);
        $this->assertNotNull($order->reserved_until);
        $this->assertSame('2026-09-04 15:00', $order->reserved_until->timezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    #[Test]
    public function order_updated_with_reserve_false_releases_reserve_and_keeps_missing_keys_intact(): void
    {
        $order = Order::factory()->create([
            'reserve' => true,
            'reserved_until' => now()->addDay(),
            'items_version' => 2,
        ]);

        // reserve: false — резерв снят (подтверждение/менеджер/неучастник)
        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'reserve' => false,
        ]);

        $order->refresh();
        $this->assertFalse($order->reserve);
        $this->assertSame(2, $order->items_version, 'items_version без ключа в payload не меняется');

        // сообщение вовсе без полей резерва — режим не меняется
        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'number' => 'ЗК-001',
        ]);

        $this->assertFalse($order->refresh()->reserve);
    }

    #[Test]
    public function partner_updated_saves_reserve_allowed_replica(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-reserve-uuid']);
        $this->assertFalse($user->refresh()->reserve_allowed, 'default колонки — не участник');

        app(HandlePartnerUpdated::class)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-1',
            'uuid' => 'partner-reserve-uuid',
            'reserve_allowed' => true,
        ]);

        $this->assertTrue($user->refresh()->reserve_allowed);

        // Сообщение без ключа признак не трогает
        app(HandlePartnerUpdated::class)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-2',
            'uuid' => 'partner-reserve-uuid',
            'phone' => '+79990000000',
        ]);

        $this->assertTrue($user->refresh()->reserve_allowed);

        app(HandlePartnerUpdated::class)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-3',
            'uuid' => 'partner-reserve-uuid',
            'reserve_allowed' => false,
        ]);

        $this->assertFalse($user->refresh()->reserve_allowed);
    }

    #[Test]
    public function reserve_policy_requires_global_flag_and_erp_flag(): void
    {
        $policy = app(ReservePolicy::class);
        $user = User::factory()->create(['reserve_allowed' => true]);

        config(['order_reserve.enabled' => false]);
        $this->assertFalse($policy->availableFor($user), 'глобальный рубильник выключен — резерв недоступен даже участнику');

        config(['order_reserve.enabled' => true]);
        $this->assertTrue($policy->availableFor($user));

        $stranger = User::factory()->create(['reserve_allowed' => false]);
        $this->assertFalse($policy->availableFor($stranger), 'без флага 1С резерв недоступен');
    }

    #[Test]
    public function site_override_narrows_but_never_expands(): void
    {
        config(['order_reserve.enabled' => true]);
        $policy = app(ReservePolicy::class);

        $participant = User::factory()->create(['reserve_allowed' => true]);
        OrderReserveOverride::create(['user_id' => $participant->id, 'disabled' => true]);
        $this->assertFalse($policy->availableFor($participant), 'точечное отключение сайта сужает охват');

        $outsider = User::factory()->create(['reserve_allowed' => false]);
        OrderReserveOverride::create(['user_id' => $outsider->id, 'disabled' => false, 'hours' => 48]);
        $this->assertFalse($policy->availableFor($outsider), 'отклонение сайта не может расширить охват без флага 1С');
    }

    #[Test]
    public function reserve_hours_default_and_override(): void
    {
        config(['order_reserve.enabled' => true, 'order_reserve.hours' => 24]);
        $policy = app(ReservePolicy::class);

        $regular = User::factory()->create(['reserve_allowed' => true]);
        $this->assertSame(24, $policy->hoursFor($regular));

        $special = User::factory()->create(['reserve_allowed' => true]);
        OrderReserveOverride::create(['user_id' => $special->id, 'hours' => 6]);
        $this->assertSame(6, $policy->hoursFor($special));

        $until = $policy->requestedReservedUntil($special);
        $this->assertEqualsWithDelta(6 * 3600, $until->diffInSeconds(now(), true), 5);
    }
}
