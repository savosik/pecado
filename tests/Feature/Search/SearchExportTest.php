<?php

namespace Tests\Feature\Search;

use App\Enums\OrderStatus;
use App\Enums\ReturnReason;
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

class SearchExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function enableExport(): void
    {
        config(['search-cabinet.export' => true]);
    }

    // ---------- Off-flag ----------

    #[Test]
    public function orders_export_returns_404_when_flag_disabled(): void
    {
        $this->actingAs($this->user)
            ->get('/cabinet/orders/export?format=csv')
            ->assertNotFound();
    }

    #[Test]
    public function returns_export_returns_404_when_flag_disabled(): void
    {
        $this->actingAs($this->user)
            ->get('/cabinet/returns/export?format=csv')
            ->assertNotFound();
    }

    #[Test]
    public function shipments_export_returns_404_when_flag_disabled(): void
    {
        $this->actingAs($this->user)
            ->get('/cabinet/shipments/export?format=csv')
            ->assertNotFound();
    }

    // ---------- On-flag ----------

    #[Test]
    public function orders_export_csv_contains_user_orders(): void
    {
        $this->enableExport();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-EXPORT-001',
            'total_amount' => 1234.56,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'price' => 1234.56,
            'quantity' => 1,
            'subtotal' => 1234.56,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/cabinet/orders/export?format=csv');

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('ORD-EXPORT-001', $body);
        $this->assertStringContainsString('Номер;Тип;Статус', $body);
    }

    #[Test]
    public function orders_export_xlsx_returns_proper_content_type(): void
    {
        $this->enableExport();

        Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-XLSX-001',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/cabinet/orders/export?format=xlsx');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    public function orders_export_unknown_format_returns_422(): void
    {
        $this->enableExport();
        $this->actingAs($this->user)
            ->get('/cabinet/orders/export?format=pdf')
            ->assertStatus(422);
    }

    #[Test]
    public function orders_export_respects_status_filter(): void
    {
        $this->enableExport();

        $confirmed = Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-CONFIRMED',
            'status' => OrderStatus::CONFIRMED,
        ]);
        $closed = Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-CLOSED',
            'status' => OrderStatus::CLOSED,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/cabinet/orders/export?format=csv&status%5B%5D='.OrderStatus::CONFIRMED->value);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('ORD-CONFIRMED', $body);
        $this->assertStringNotContainsString('ORD-CLOSED', $body);
    }

    #[Test]
    public function orders_export_does_not_leak_other_users(): void
    {
        $this->enableExport();

        $other = User::factory()->create();
        Order::factory()->create([
            'user_id' => $other->id,
            'number' => 'ORD-FOREIGN-XYZ',
        ]);
        Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-MINE-ABC',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/cabinet/orders/export?format=csv');

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('ORD-MINE-ABC', $body);
        $this->assertStringNotContainsString('ORD-FOREIGN-XYZ', $body);
    }

    #[Test]
    public function returns_export_csv_contains_user_returns(): void
    {
        $this->enableExport();

        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);
        $return = ProductReturn::factory()->create([
            'user_id' => $this->user->id,
            'erp_number' => 'RET-EXPORT-001',
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'shipment_item_id' => $shipmentItem->id,
            'shipment_id' => $shipment->id,
            'product_id' => $shipmentItem->product_id,
            'quantity' => 1,
            'reason' => ReturnReason::DEFECTIVE,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/cabinet/returns/export?format=csv');

        $response->assertOk();
        $this->assertStringContainsString('RET-EXPORT-001', $response->streamedContent());
    }

    #[Test]
    public function shipments_export_csv_contains_user_shipments(): void
    {
        $this->enableExport();

        $shipment = Shipment::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'SHP-EXPORT-001',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/cabinet/shipments/export?format=csv');

        $response->assertOk();
        $this->assertStringContainsString('SHP-EXPORT-001', $response->streamedContent());
    }
}
