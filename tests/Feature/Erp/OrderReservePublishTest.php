<?php

namespace Tests\Feature\Erp;

use App\Events\OrderDeleted;
use App\Events\OrderUpdated;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Erp\OrderReservePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-03): исходящие события окна резерва
 * публикуются явно через OrderReservePublisher, проходят валидацию по новым
 * схемам, а модельные события Eloquent больше не порождают order.updated /
 * order.deleted в шину.
 */
class OrderReservePublishTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrderWithItem(): Order
    {
        $user = User::factory()->create(['erp_id' => 'partner-erp-uuid-1']);
        $product = Product::factory()->create(['external_id' => 'prod-ext-001']);

        $order = Order::factory()->create(['user_id' => $user->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'line_number' => 1,
            'quantity' => 3,
            'price' => 1000.00,
            'base_price' => 1000.00,
            'discount_percent' => 10,
            'final_price' => 900.00,
            'subtotal' => 2700.00,
        ]);

        return $order->fresh();
    }

    #[Test]
    public function publish_updated_dispatches_valid_payload_with_full_items(): void
    {
        Queue::fake();
        $order = $this->makeOrderWithItem();

        app(OrderReservePublisher::class)->publishUpdated($order, 3);

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($order) {
            $payload = $job->payload;

            $this->assertSame('order.updated', $payload['event']);
            $this->assertSame($order->uuid, $payload['uuid']);
            $this->assertSame(3, $payload['base_items_version']);
            $this->assertStringStartsWith('msg-', $payload['message_id']);
            $this->assertCount(1, $payload['items']);
            $this->assertSame(1, $payload['items'][0]['line_number']);
            $this->assertSame('prod-ext-001', $payload['items'][0]['product_uuid']);

            $validation = app(ErpMessageValidator::class)->validateOutbound('order.updated', $payload);
            $this->assertTrue($validation['valid'], implode('; ', $validation['errors']));

            return true;
        });
    }

    #[Test]
    public function publish_deleted_dispatches_valid_payload_for_both_reasons(): void
    {
        Queue::fake();
        $order = $this->makeOrderWithItem();
        $publisher = app(OrderReservePublisher::class);

        $publisher->publishDeleted($order, OrderReservePublisher::REASON_CLIENT_CANCELLED);
        $publisher->publishDeleted($order, OrderReservePublisher::REASON_RESERVE_EXPIRED);

        $reasons = [];
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$reasons) {
            $payload = $job->payload;

            $validation = app(ErpMessageValidator::class)->validateOutbound('order.deleted', $payload);
            $this->assertTrue($validation['valid'], implode('; ', $validation['errors']));

            $reasons[] = $payload['reason'];

            return $payload['event'] === 'order.deleted';
        });

        $this->assertEqualsCanonicalizing(['client_cancelled', 'reserve_expired'], $reasons);
    }

    #[Test]
    public function publish_confirmed_dispatches_valid_payload(): void
    {
        Queue::fake();
        $order = $this->makeOrderWithItem();

        app(OrderReservePublisher::class)->publishConfirmed($order);

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) {
            $payload = $job->payload;

            $this->assertSame('order.confirmed', $payload['event']);
            $this->assertNotEmpty($payload['confirmed_at']);

            $validation = app(ErpMessageValidator::class)->validateOutbound('order.confirmed', $payload);
            $this->assertTrue($validation['valid'], implode('; ', $validation['errors']));

            return true;
        });
    }

    #[Test]
    public function outbound_validator_rejects_unknown_cancellation_reason(): void
    {
        $validation = app(ErpMessageValidator::class)->validateOutbound('order.deleted', [
            'event' => 'order.deleted',
            'message_id' => 'msg-test',
            'uuid' => 'a81bc81b-dead-4e5d-abff-90865d1e13b1',
            'reason' => 'manager_mistake',
        ]);

        $this->assertFalse($validation['valid']);
    }

    #[Test]
    public function outbound_validator_rejects_updated_without_base_items_version(): void
    {
        $validation = app(ErpMessageValidator::class)->validateOutbound('order.updated', [
            'event' => 'order.updated',
            'message_id' => 'msg-test',
            'uuid' => 'a81bc81b-dead-4e5d-abff-90865d1e13b1',
            'items' => [['line_number' => 1, 'quantity' => 1]],
        ]);

        $this->assertFalse($validation['valid']);
    }

    #[Test]
    public function reserve_in_order_created_requires_reserved_until(): void
    {
        $validator = app(ErpMessageValidator::class);

        $base = [
            'event' => 'order.created',
            'message_id' => 'msg-test',
            'uuid' => 'a81bc81b-dead-4e5d-abff-90865d1e13b1',
            'items' => [['quantity' => 1, 'base_price' => 100, 'discount_percent' => 0, 'final_price' => 100]],
        ];

        $withoutUntil = $validator->validateOutbound('order.created', $base + ['reserve' => true]);
        $this->assertFalse($withoutUntil['valid'], 'reserve=true без reserved_until должен отклоняться');

        $withUntil = $validator->validateOutbound('order.created', $base + [
            'reserve' => true,
            'reserved_until' => now()->addDay()->toIso8601String(),
        ]);
        $this->assertTrue($withUntil['valid'], implode('; ', $withUntil['errors']));

        $ordinary = $validator->validateOutbound('order.created', $base);
        $this->assertTrue($ordinary['valid'], 'обычный заказ без reserve должен проходить как раньше');
    }

    #[Test]
    public function model_events_no_longer_publish_updated_or_deleted_to_bus(): void
    {
        $order = $this->makeOrderWithItem();

        Queue::fake();

        event(new OrderUpdated($order));
        event(new OrderDeleted($order));

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }
}
