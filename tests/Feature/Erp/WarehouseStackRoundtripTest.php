<?php

namespace Tests\Feature\Erp;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Handlers\HandleOrderCreated;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Roundtrip-безопасность зафиксированного склада (режим стопки складов).
 *
 * `orders.assigned_warehouse_id` пишет только сайт при оформлении. Входящие
 * `order.created` / `order.updated` от 1С (в том числе с пересозданием позиций
 * и складом проведения `warehouse_uuid`) не должны его затирать — прецедент
 * затирания складских привязок уже был (PreservesDefectItemLinks).
 */
class WarehouseStackRoundtripTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_UUID = '550e8400-e29b-41d4-a716-446655441001';

    private User $user;

    private Product $product;

    private Warehouse $assigned;

    private Warehouse $other;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => '550e8400-e29b-41d4-a716-446655441002']);
        $this->product = Product::factory()->create(['external_id' => '550e8400-e29b-41d4-a716-446655441003']);
        $this->assigned = Warehouse::factory()->create(['external_id' => 'stack-rt-assigned']);
        $this->other = Warehouse::factory()->create(['external_id' => 'stack-rt-other']);

        // Заказ, оформленный на сайте с зафиксированным складом стопки.
        $this->order = Order::factory()->create([
            'uuid' => self::ORDER_UUID,
            'user_id' => $this->user->id,
            'assigned_warehouse_id' => $this->assigned->id,
            'type' => \App\Enums\OrderType::ORDER,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'event' => 'order.created',
            'message_id' => 'msg-stack-roundtrip',
            'uuid' => self::ORDER_UUID,
            'number' => '29УТ-000777',
            'status' => 'ready_for_provision',
            'partner_uuid' => $this->user->erp_id,
            'items' => [[
                'line_number' => 1,
                'product_uuid' => $this->product->external_id,
                'quantity' => 2,
                'base_price' => 100,
                'final_price' => 100,
            ]],
        ], $override);
    }

    #[Test]
    public function order_created_от_1с_не_затирает_зафиксированный_склад(): void
    {
        app(HandleOrderCreated::class)->handle($this->payload([
            'warehouse_uuid' => 'stack-rt-other',
        ]));

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        // Факт проведения записан, назначение сайта не тронуто.
        $this->assertSame($this->other->id, $order->warehouse_id);
        $this->assertSame($this->assigned->id, $order->assigned_warehouse_id);
    }

    #[Test]
    public function order_updated_с_пересозданием_позиций_сохраняет_зафиксированный_склад(): void
    {
        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'message_id' => 'msg-stack-roundtrip-upd',
            'uuid' => self::ORDER_UUID,
            'status' => 'ready_for_shipment',
            'warehouse_uuid' => 'stack-rt-other',
            'items' => [[
                'line_number' => 1,
                'product_uuid' => $this->product->external_id,
                'quantity' => 1,
                'base_price' => 100,
                'final_price' => 100,
            ]],
        ]);

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        $this->assertSame($this->other->id, $order->warehouse_id);
        $this->assertSame($this->assigned->id, $order->assigned_warehouse_id);
    }

    #[Test]
    public function отсутствие_склада_в_payload_не_меняет_ни_одно_из_полей(): void
    {
        $this->order->update(['warehouse_id' => $this->other->id]);

        app(HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'message_id' => 'msg-stack-roundtrip-noop',
            'uuid' => self::ORDER_UUID,
            'status' => 'ready_for_shipment',
        ]);

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        $this->assertSame($this->other->id, $order->warehouse_id);
        $this->assertSame($this->assigned->id, $order->assigned_warehouse_id);
    }
}
