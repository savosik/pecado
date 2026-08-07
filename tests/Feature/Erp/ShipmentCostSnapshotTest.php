<?php

namespace Tests\Feature\Erp;

use App\Models\Product;
use App\Models\Shipment;
use App\Services\Erp\Handlers\HandleCostUpdated;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Снимок себестоимости в строке реализации (US-18, v15.13.0).
 *
 * Смысл снимка: себестоимость меняется во времени, а реализация — исторический
 * документ. Без снимка прибыль за прошлый период пересчитывалась бы при каждом
 * cost.updated, то есть отчёт «ехал» бы задним числом.
 */
class ShipmentCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_captures_cost_snapshot_when_shipment_arrives(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'snapshot-product-uuid',
        ]);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'snapshot-product-uuid',
            'cost' => 500.00,
        ]);

        (new HandleShipmentCreated)->handle($this->shipmentPayload('snapshot-product-uuid'));

        $item = Shipment::where('uuid', '550e8400-e29b-41d4-a716-4466554400aa')
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertEquals($product->id, $item->product_id);
        $this->assertEquals(500.00, (float) $item->cost_price_snapshot);
    }

    #[Test]
    public function later_cost_updates_do_not_rewrite_existing_snapshot(): void
    {
        Product::factory()->create(['external_id' => 'snapshot-product-uuid']);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'snapshot-product-uuid',
            'cost' => 500.00,
        ]);

        (new HandleShipmentCreated)->handle($this->shipmentPayload('snapshot-product-uuid'));

        // Себестоимость выросла уже после проведения документа.
        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'snapshot-product-uuid',
            'cost' => 900.00,
        ]);

        $item = Shipment::where('uuid', '550e8400-e29b-41d4-a716-4466554400aa')
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertEquals(500.00, (float) $item->cost_price_snapshot);
    }

    #[Test]
    public function snapshot_is_null_when_product_has_no_cost(): void
    {
        Product::factory()->create(['external_id' => 'snapshot-product-uuid']);

        (new HandleShipmentCreated)->handle($this->shipmentPayload('snapshot-product-uuid'));

        $item = Shipment::where('uuid', '550e8400-e29b-41d4-a716-4466554400aa')
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertNull($item->cost_price_snapshot);
    }

    #[Test]
    public function snapshot_is_captured_for_hidden_product(): void
    {
        // HiddenScope прячет снятые с публикации товары, но реализации по ним приходят.
        Product::factory()->create([
            'external_id' => 'snapshot-product-uuid',
            'hidden' => true,
        ]);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'snapshot-product-uuid',
            'cost' => 333.00,
        ]);

        (new HandleShipmentCreated)->handle($this->shipmentPayload('snapshot-product-uuid'));

        $item = Shipment::where('uuid', '550e8400-e29b-41d4-a716-4466554400aa')
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertEquals(333.00, (float) $item->cost_price_snapshot);
        $this->assertNotNull($item->product_name_snapshot);
    }

    #[Test]
    public function snapshot_is_hidden_from_serialization(): void
    {
        Product::factory()->create(['external_id' => 'snapshot-product-uuid']);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'snapshot-product-uuid',
            'cost' => 500.00,
        ]);

        (new HandleShipmentCreated)->handle($this->shipmentPayload('snapshot-product-uuid'));

        $item = Shipment::where('uuid', '550e8400-e29b-41d4-a716-4466554400aa')
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertArrayNotHasKey('cost_price_snapshot', $item->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentPayload(string $productUuid): array
    {
        return [
            'event' => 'shipment.created',
            'message_id' => 'msg-shipment-snapshot',
            'uuid' => '550e8400-e29b-41d4-a716-4466554400aa',
            'number' => '29УТ-000001',
            'date' => '2026-08-07',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                [
                    'product_uuid' => $productUuid,
                    'quantity' => 2,
                    'price' => 1000.00,
                    'total' => 2000.00,
                ],
            ],
        ];
    }
}
