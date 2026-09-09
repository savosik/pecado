<?php

namespace Tests\Feature\Erp;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.1 (режим «Заказы в резерве»): эхо order.updated (status=closed), которое
 * 1С шлёт в ответ на нашу отмену резерва (order.deleted, reason=client_cancelled →
 * в 1С «Закрыт»), НЕ должно воскрешать soft-deleted заказ.
 *
 * Найдено на канареечном smoke-тесте 09.09.2026: отменённый резервный заказ
 * оживал при приёме обратного эха, потому что обработчик восстанавливал любой
 * trashed-заказ со статусом, отличным от «Удалён».
 */
class CancelledReserveEchoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function closed_echo_does_not_revive_cancelled_order(): void
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::CLOSED,
            'reserve' => false,
        ]);
        $order->delete(); // отмена: soft-delete + closed (как в ClientOrderActions::cancel)
        $this->assertTrue($order->fresh() === null || Order::withTrashed()->find($order->id)->trashed());

        // 1С присылает эхо своей обработки order.deleted: полный order.updated,
        // status=closed, reserve=false, строка cancelled
        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'status' => 'closed',
            'reserve' => false,
            'items_version' => 2,
        ]);

        $fresh = Order::withTrashed()->find($order->id);
        $this->assertTrue($fresh->trashed(), 'отменённый заказ остался soft-deleted, эхо его не оживило');
        $this->assertSame(OrderStatus::CLOSED, $fresh->status);
    }

    #[Test]
    public function active_status_still_revives_trashed_order(): void
    {
        // Регресс-гарантия обратного: реальное возвращение заказа в работу из 1С
        // (v15.4 — 1С прислала АКТИВНЫЙ статус) по-прежнему восстанавливает его.
        $order = Order::factory()->create(['status' => OrderStatus::CLOSED]);
        $order->delete();

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'status' => 'ready_for_shipment',
        ]);

        $fresh = Order::withTrashed()->find($order->id);
        $this->assertFalse($fresh->trashed(), 'активный статус из 1С возвращает заказ к жизни');
        $this->assertSame(OrderStatus::READY_FOR_SHIPMENT, $fresh->status);
    }

    #[Test]
    public function reserve_false_echo_clears_historical_until(): void
    {
        // 1С предупредила: в эхе с reserve=false срок может остаться историческим.
        // Снятый резерв не должен нести reserved_until.
        $order = Order::factory()->create([
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addDay(),
        ]);

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'reserve' => false,
            'reserved_until' => now()->addDay()->toIso8601String(), // исторический срок
            'items_version' => 2,
        ]);

        $fresh = $order->fresh();
        $this->assertFalse((bool) $fresh->reserve);
        $this->assertNull($fresh->reserved_until, 'срок снятого резерва обнулён, исторический не сохранён');
    }

    #[Test]
    public function conflict_echo_restoring_reserve_clears_terminal_outcome(): void
    {
        // Найдено на smoke-тесте: сайт состарил срок только у себя и снял резерв
        // по таймауту (reserve_outcome=expired), 1С отбила снятие (её срок ещё не
        // истёк) и вернула резерв конфликтным эхом reserve=true. Терминальный
        // исход должен обнулиться — иначе РОП-отчёт посчитает живой резерв истёкшим.
        $order = Order::factory()->create([
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => false,
            'reserve_outcome' => 'expired',
            'reserved_until' => null,
        ]);

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'status' => 'ready_for_shipment',
            'reserve' => true,
            'reserved_until' => now()->addDay()->toIso8601String(),
            'items_version' => 3,
        ]);

        $fresh = $order->fresh();
        $this->assertTrue((bool) $fresh->reserve, 'резерв восстановлен');
        $this->assertNull($fresh->reserve_outcome, 'терминальный исход погашен — резерв снова активен');
        $this->assertNotNull($fresh->reserved_until, 'срок из эха применён');
    }

    #[Test]
    public function confirmed_outcome_survives_reserve_false_echo(): void
    {
        // Обратная гарантия: подтверждение (reserve_outcome=confirmed, reserve=false)
        // приходит с reserve=false — эхо не должно стирать зафиксированный исход.
        $order = Order::factory()->create([
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => false,
            'reserve_outcome' => 'confirmed',
        ]);

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'reserve' => false,
            'items_version' => 2,
        ]);

        $this->assertSame('confirmed', $order->fresh()->reserve_outcome, 'исход подтверждения сохранён');
    }

    #[Test]
    public function duplicate_closed_echoes_are_idempotent(): void
    {
        // Дубль эха (дефект отправителя 1С на smoke-тесте): повторное закрытое
        // эхо не меняет состояние повторно и не оживляет.
        $order = Order::factory()->create(['status' => OrderStatus::CLOSED, 'reserve' => false]);
        $order->delete();

        $payload = [
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'status' => 'closed',
            'reserve' => false,
            'items_version' => 2,
        ];
        app(HandleOrderUpdated::class)->handle($payload);
        app(HandleOrderUpdated::class)->handle($payload);

        $fresh = Order::withTrashed()->find($order->id);
        $this->assertTrue($fresh->trashed());
        $this->assertFalse((bool) $fresh->reserve);
    }
}
