<?php

namespace Tests\Feature\Search;

use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentItemSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->brand = Brand::create(['name' => 'BrandSnap', 'slug' => 'brand-snap']);
        $this->product = Product::factory()->create([
            'name' => 'Кроссовки SnapShot',
            'brand_id' => $this->brand->id,
        ]);
    }

    #[Test]
    public function order_item_fills_snapshot_on_create(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        $this->assertSame('Кроссовки SnapShot', $item->name);
        $this->assertSame('BrandSnap', $item->brand_name_snapshot);
    }

    #[Test]
    public function order_item_keeps_explicit_snapshot_values(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => 'Историческое имя товара',
            'brand_name_snapshot' => 'Исторический бренд',
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        $this->assertSame('Историческое имя товара', $item->name);
        $this->assertSame('Исторический бренд', $item->brand_name_snapshot);
    }

    #[Test]
    public function order_item_searchable_array_contains_scope_and_snapshots(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-TEST-1',
            'erp_number' => '29УТ-001',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        $arr = $item->toSearchableArray();

        $this->assertSame($item->id, $arr['id']);
        $this->assertSame($order->id, $arr['order_id']);
        $this->assertSame('ORD-TEST-1', $arr['order_number']);
        $this->assertSame('29УТ-001', $arr['order_erp_number']);
        $this->assertSame($this->user->id, $arr['user_id']);
        $this->assertSame($this->product->id, $arr['product_id']);
        $this->assertSame('Кроссовки SnapShot', $arr['product_name_snapshot']);
        $this->assertSame('BrandSnap', $arr['brand_name_snapshot']);
    }

    #[Test]
    public function return_item_fills_snapshot_on_create(): void
    {
        $return = ProductReturn::factory()->create(['user_id' => $this->user->id]);
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $item = ReturnItem::create([
            'return_id' => $return->id,
            'shipment_item_id' => $shipmentItem->id,
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'reason' => \App\Enums\ReturnReason::DEFECTIVE,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $this->assertSame('Кроссовки SnapShot', $item->product_name_snapshot);
        $this->assertSame('BrandSnap', $item->brand_name_snapshot);
    }

    #[Test]
    public function return_item_searchable_array_scopes_by_user(): void
    {
        $return = ProductReturn::factory()->create([
            'user_id' => $this->user->id,
            'erp_number' => 'RET-001',
        ]);
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $item = ReturnItem::create([
            'return_id' => $return->id,
            'shipment_item_id' => $shipmentItem->id,
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'reason' => \App\Enums\ReturnReason::DEFECTIVE,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $arr = $item->toSearchableArray();

        $this->assertSame($return->id, $arr['return_id']);
        $this->assertSame('RET-001', $arr['return_erp_number']);
        $this->assertSame($this->user->id, $arr['user_id']);
        $this->assertSame('Кроссовки SnapShot', $arr['product_name_snapshot']);
        $this->assertSame('BrandSnap', $arr['brand_name_snapshot']);
    }

    #[Test]
    public function shipment_item_fills_snapshot_on_create(): void
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);

        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $this->assertSame('Кроссовки SnapShot', $item->product_name_snapshot);
        $this->assertSame('BrandSnap', $item->brand_name_snapshot);
    }

    #[Test]
    public function shipment_item_searchable_array_scopes_by_user(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'SHP-TEST-1',
            'erp_number' => '29РЕ-001',
        ]);

        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $arr = $item->toSearchableArray();

        $this->assertSame($shipment->id, $arr['shipment_id']);
        $this->assertSame('SHP-TEST-1', $arr['shipment_number']);
        $this->assertSame('29РЕ-001', $arr['shipment_erp_number']);
        $this->assertSame($this->user->id, $arr['user_id']);
        $this->assertSame('Кроссовки SnapShot', $arr['product_name_snapshot']);
        $this->assertSame('BrandSnap', $arr['brand_name_snapshot']);
    }

    #[Test]
    public function snapshot_skipped_when_product_id_missing(): void
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);

        $item = new ShipmentItem([
            'shipment_id' => $shipment->id,
            'product_id' => null,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);
        $item->save();

        $this->assertNull($item->product_name_snapshot);
        $this->assertNull($item->brand_name_snapshot);
    }
}
