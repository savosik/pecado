<?php

namespace Tests\Feature\Erp;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\ErpValidationError;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Проект контракта предзаказа v16.18.0 (топик Agent Hub №18), этап 1 — только контракт.
 *
 * Сообщения идут через `ErpIncomingJob`, то есть сквозь валидацию по JSON Schema. Проверяется,
 * что новые необязательные поля не ломают то, что уже работает: 1С может включить свою
 * доработку (`expected_date`, `type` всегда, проведение предзаказа на «Москва Основной»)
 * раньше, чем сайт начнёт эти поля применять. Применение полей и приём
 * `preorder_offers.updated` — этап 2, его тесты придут вместе с обработчиком.
 */
class PreorderContractIngestTest extends TestCase
{
    use RefreshDatabase;

    private const MAIN_WAREHOUSE = '40301d16-3847-11e1-8034-001e6711ed1d';

    private const PREORDER_WAREHOUSE = '38dcd8b2-be0a-4861-974f-44c2a71e7789';

    private const ORDER_UUID = '00000000-0000-4000-a000-000000000181';

    /** @param array<string, mixed> $payload */
    private function fire(array $payload, string $queue = 'erp_in.orders'): void
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            $queue,
        ))->fire();
    }

    private function sitePreorder(): Order
    {
        return Order::factory()->create([
            'uuid' => self::ORDER_UUID,
            'user_id' => User::factory()->create()->id,
            'type' => OrderType::PREORDER,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);
    }

    /**
     * Ответ 1С на предзаказ: документ проведён на «Москва Основной», тип остаётся preorder,
     * в сообщении новая ожидаемая дата. Заказ должен обновиться и остаться предзаказом.
     */
    #[Test]
    public function order_updated_with_expected_date_and_main_warehouse_keeps_preorder_type(): void
    {
        $main = Warehouse::factory()->create(['name' => 'Москва Основной', 'external_id' => self::MAIN_WAREHOUSE]);
        $order = $this->sitePreorder();

        $this->fire([
            'event' => 'order.updated',
            'message_id' => 'msg-preorder-updated-0001',
            'uuid' => $order->uuid,
            'number' => '29УТ-015001',
            'type' => 'preorder',
            'status' => 'awaiting_provision',
            'warehouse_uuid' => self::MAIN_WAREHOUSE,
            'expected_date' => '2026-10-20',
        ]);

        $order->refresh();

        $this->assertSame(0, ErpValidationError::count());
        $this->assertSame(OrderType::PREORDER, $order->type);
        $this->assertSame(OrderStatus::AWAITING_PROVISION, $order->status);
        $this->assertSame('29УТ-015001', $order->erp_number);
        $this->assertSame($main->id, $order->warehouse_id);
    }

    /**
     * `expected_date` — дата без времени. Дата-время схему не проходит: сообщение отклонено,
     * заказ не тронут.
     */
    #[Test]
    public function order_updated_with_datetime_in_expected_date_is_rejected_by_schema(): void
    {
        $order = $this->sitePreorder();

        $this->fire([
            'event' => 'order.updated',
            'message_id' => 'msg-preorder-updated-0002',
            'uuid' => $order->uuid,
            'type' => 'preorder',
            'status' => 'awaiting_provision',
            'expected_date' => '2026-10-20T00:00:00+03:00',
        ]);

        $order->refresh();

        $this->assertSame(1, ErpValidationError::where('event', 'order.updated')->count());
        $this->assertSame(OrderStatus::PENDING_APPROVAL, $order->status);
    }

    /**
     * С v16.18.0 1С присылает `type` всегда. `order.updated` с `type: order` по обычному заказу
     * проходит схему и применяется как раньше.
     */
    #[Test]
    public function order_updated_with_type_order_is_applied_to_ordinary_order(): void
    {
        $order = Order::factory()->create([
            'uuid' => self::ORDER_UUID,
            'user_id' => User::factory()->create()->id,
            'type' => OrderType::ORDER,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);

        $this->fire([
            'event' => 'order.updated',
            'message_id' => 'msg-order-updated-0003',
            'uuid' => $order->uuid,
            'type' => 'order',
            'status' => 'ready_for_shipment',
        ]);

        $order->refresh();

        $this->assertSame(0, ErpValidationError::count());
        $this->assertSame(OrderType::ORDER, $order->type);
        $this->assertSame(OrderStatus::READY_FOR_SHIPMENT, $order->status);
    }

    /**
     * Этап 1: схема `preorder_offers.updated` зарегистрирована, обработчика и очереди ещё нет.
     * Если сообщение всё же дойдёт до воркера, оно снимается с очереди без исключения и без
     * записи в журнал ошибок валидации. На этапе 2 этот тест заменят тесты обработчика.
     */
    #[Test]
    public function preorder_offers_event_without_handler_is_dropped_quietly(): void
    {
        $this->fire([
            'event' => 'preorder_offers.updated',
            'message_id' => 'msg-preorder-offers-0001',
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'warehouse_uuid' => self::PREORDER_WAREHOUSE,
            'calculated_at' => '2026-10-02T15:00:00.123+03:00',
            'offers' => [
                ['quantity' => 20, 'lead_time_days' => 5],
                ['quantity' => 100, 'lead_time_days' => 14],
            ],
        ], 'erp_in.preorder_offers');

        $this->assertSame(0, ErpValidationError::count());
    }
}
