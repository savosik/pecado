<?php

namespace Tests\Feature\Erp;

use App\Enums\DefectClosedReason;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use App\Services\Erp\Handlers\HandleShipmentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Списание партий некондиции по реализации из 1С (v15.5).
 */
class DefectShipmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Заказ уценки на партию — как его создаёт checkout.
     */
    private function defectOrder(ProductDefect $defect, int $quantity, string $uuid): Order
    {
        $order = Order::factory()->create(['type' => OrderType::DEFECT, 'uuid' => $uuid]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'defect_description' => $defect->defect_description,
            'name' => 'Уценка',
            'price' => $defect->price,
            'base_price' => $defect->price,
            'discount_percent' => 0,
            'final_price' => $defect->price,
            'quantity' => $quantity,
            'subtotal' => $defect->price * $quantity,
        ]);

        return $order;
    }

    private function shipmentPayload(string $uuid, string $orderUuid, Product $product, int $quantity): array
    {
        return [
            'event' => 'shipment.created',
            'uuid' => $uuid,
            'number' => 'REAL-'.$uuid,
            'date' => '2026-07-22',
            'status' => 'completed',
            'items' => [
                [
                    'product_uuid' => $product->external_id,
                    'order_uuid' => $orderUuid,
                    'quantity' => $quantity,
                    'price' => 100,
                ],
            ],
        ];
    }

    #[Test]
    public function full_shipment_closes_the_defect_batch(): void
    {
        $product = Product::factory()->create(['external_id' => 'def-prod-1']);
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 3]);
        $order = $this->defectOrder($defect, 3, 'ord-defect-1');

        (new HandleShipmentCreated)->handle(
            $this->shipmentPayload('ship-1', $order->uuid, $product, 3)
        );

        $defect->refresh();
        $this->assertTrue($defect->isClosed());
        $this->assertSame(DefectClosedReason::SOLD_OUT, $defect->closed_reason);
    }

    #[Test]
    public function partial_shipment_keeps_batch_open(): void
    {
        $product = Product::factory()->create(['external_id' => 'def-prod-2']);
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 5]);
        $order = $this->defectOrder($defect, 5, 'ord-defect-2');

        (new HandleShipmentCreated)->handle(
            $this->shipmentPayload('ship-2', $order->uuid, $product, 2)
        );

        $this->assertFalse($defect->fresh()->isClosed());
    }

    #[Test]
    public function repeated_shipment_does_not_double_count(): void
    {
        $product = Product::factory()->create(['external_id' => 'def-prod-3']);
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 3]);
        $order = $this->defectOrder($defect, 3, 'ord-defect-3');

        $payload = $this->shipmentPayload('ship-3', $order->uuid, $product, 3);

        // Двойная доставка одного и того же документа (created + updated) —
        // пересчёт идемпотентен, партия закрыта, но не «списана дважды».
        (new HandleShipmentCreated)->handle($payload);
        (new HandleShipmentCreated)->handle($payload);

        $defect->refresh();
        $this->assertTrue($defect->isClosed());
        // Отгружено ровно 3 — суммарно по одной (не двум) реализациям.
        $this->assertSame(1, \App\Models\Shipment::where('uuid', 'ship-3')->count());
    }

    #[Test]
    public function deleting_shipment_reopens_the_batch(): void
    {
        $product = Product::factory()->create(['external_id' => 'def-prod-4']);
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 3]);
        $order = $this->defectOrder($defect, 3, 'ord-defect-4');

        (new HandleShipmentCreated)->handle(
            $this->shipmentPayload('ship-4', $order->uuid, $product, 3)
        );
        $this->assertTrue($defect->fresh()->isClosed());

        (new HandleShipmentDeleted)->handle(['event' => 'shipment.deleted', 'uuid' => 'ship-4']);

        $reopened = $defect->fresh();
        $this->assertFalse($reopened->isClosed(), 'Отмена реализации должна вернуть партию в продажу');
        $this->assertNull($reopened->closed_reason);
    }

    #[Test]
    public function manually_written_off_batch_is_not_reopened_by_shipment(): void
    {
        $product = Product::factory()->create(['external_id' => 'def-prod-5']);
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 3]);
        $defect->close(DefectClosedReason::WRITTEN_OFF);
        $order = $this->defectOrder($defect, 1, 'ord-defect-5');

        // Частичная реализация не должна «оживить» списанную вручную партию.
        (new HandleShipmentCreated)->handle(
            $this->shipmentPayload('ship-5', $order->uuid, $product, 1)
        );

        $defect->refresh();
        $this->assertTrue($defect->isClosed());
        $this->assertSame(DefectClosedReason::WRITTEN_OFF, $defect->closed_reason);
    }

    #[Test]
    public function regular_order_shipment_does_not_touch_defects(): void
    {
        // Реализация обычного заказа не должна ничего списывать по некондиции.
        $product = Product::factory()->create(['external_id' => 'def-prod-6']);
        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 3]);

        $regularOrder = Order::factory()->create(['type' => OrderType::ORDER, 'uuid' => 'ord-regular-6']);

        (new HandleShipmentCreated)->handle(
            $this->shipmentPayload('ship-6', $regularOrder->uuid, $product, 3)
        );

        $this->assertFalse($defect->fresh()->isClosed());
    }
}
