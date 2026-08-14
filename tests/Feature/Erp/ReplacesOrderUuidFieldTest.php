<?php

namespace Tests\Feature\Erp;

use App\Events\OrderCreated;
use App\Jobs\PublishOrderToErpJob;
use App\Listeners\PublishOrderToErp;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpMessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Протокол v16.2.0: необязательное поле replaces_order_uuid в order.created (sub-09).
 *
 * Поле уходит только у заказов-замен и только под флагом — до подтверждения
 * стороны 1С обычные payload-ы не меняются байт-в-байт.
 */
class ReplacesOrderUuidFieldTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{order: Order, source: Order}
     */
    private function makeReplacementOrder(): array
    {
        $user = User::factory()->create(['erp_id' => 'partner-uuid-1']);
        $product = Product::factory()->create(['external_id' => 'product-uuid-1']);

        $source = Order::factory()->create([
            'user_id' => $user->id,
            'uuid' => '99999999-0000-4000-a000-000000000001',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'manager_comment' => 'Замена недоборов по заказу 29УТ-011777',
        ]);
        $order->replacement_for_order_id = $source->id;
        $order->saveQuietly();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        return ['order' => $order->fresh(), 'source' => $source];
    }

    private function publish(Order $order): array
    {
        Queue::fake();

        (new PublishOrderToErp)->handle(new OrderCreated($order));

        $payload = null;
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$payload) {
            $payload = $job->payload;

            return true;
        });

        return $payload;
    }

    #[Test]
    public function replacement_order_carries_the_source_uuid_when_the_flag_is_on(): void
    {
        config(['substitutions.protocol_field_enabled' => true]);

        ['order' => $order, 'source' => $source] = $this->makeReplacementOrder();

        $payload = $this->publish($order);

        $this->assertSame($source->uuid, $payload['replaces_order_uuid']);
        $this->assertSame('Замена недоборов по заказу 29УТ-011777', $payload['manager_comment']);

        $result = app(ErpMessageValidator::class)->validateOutbound('order.created', $payload);
        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    #[Test]
    public function an_ordinary_order_payload_does_not_contain_the_field_at_all(): void
    {
        config(['substitutions.protocol_field_enabled' => true]);

        $user = User::factory()->create(['erp_id' => 'partner-uuid-2']);
        $product = Product::factory()->create(['external_id' => 'product-uuid-2']);
        $order = Order::factory()->create(['user_id' => $user->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);

        $payload = $this->publish($order->fresh());

        $this->assertArrayNotHasKey('replaces_order_uuid', $payload);
    }

    #[Test]
    public function the_field_is_held_back_while_the_flag_is_off(): void
    {
        config(['substitutions.protocol_field_enabled' => false]);

        ['order' => $order] = $this->makeReplacementOrder();

        $payload = $this->publish($order);

        // Связь есть, но поле не уходит: ждём подтверждения 1С.
        $this->assertArrayNotHasKey('replaces_order_uuid', $payload);
        $this->assertStringContainsString('Замена недоборов', $payload['manager_comment']);
    }
}
