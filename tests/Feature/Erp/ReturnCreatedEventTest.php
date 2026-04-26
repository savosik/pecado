<?php

namespace Tests\Feature\Erp;

use App\Events\ReturnCreated;
use App\Jobs\PublishReturnToErpJob;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnCreatedEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function return_created_event_dispatches_publish_job_with_shipment_payload(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create(['erp_id' => 'partner-ret-evt-001']);
        $product = Product::factory()->create(['external_id' => 'product-ret-evt-001']);

        $shipment = Shipment::create([
            'uuid' => 'shipment-ret-evt-uuid-001',
            'number' => '29УТ-EVT-001',
            'user_id' => $user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 6000,
        ]);

        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'order_uuid' => null,
            'quantity' => 5,
            'price' => 3000,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'total' => 15000,
            'subtotal' => 15000,
            'vat_rate' => 20,
        ]);

        $return = ProductReturn::create([
            'uuid' => 'return-evt-uuid-001',
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 6000,
        ]);

        $return->items()->create([
            'shipment_item_id' => $shipmentItem->id,
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'reason' => 'defective',
            'price' => 3000,
            'subtotal' => 6000,
        ]);

        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            $payload = $job->payload;
            $item = $payload['items'][0] ?? [];

            return $payload['event'] === 'return.created'
                && is_string($payload['message_id'] ?? null)
                && preg_match('/^msg-[0-9a-f-]{36}$/', $payload['message_id']) === 1
                && $payload['uuid'] === 'return-evt-uuid-001'
                && ! array_key_exists('order_uuid', $payload)
                && $payload['partner_uuid'] === 'partner-ret-evt-001'
                && count($payload['items']) === 1
                && $item['product_uuid'] === 'product-ret-evt-001'
                && $item['shipment_uuid'] === 'shipment-ret-evt-uuid-001'
                && $item['shipment_number'] === '29УТ-EVT-001'
                && $item['quantity'] === 2
                && abs($item['price'] - 3000) < 0.001
                && $item['currency_code'] === 'RUB'
                && abs($item['subtotal'] - 6000) < 0.001
                && $item['reason'] === 'defective';
        });
    }

    #[Test]
    public function return_created_event_includes_multiple_items_from_different_shipments(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create(['erp_id' => 'partner-multi-001']);
        $product1 = Product::factory()->create(['external_id' => 'product-multi-001']);
        $product2 = Product::factory()->create(['external_id' => 'product-multi-002']);

        $shipmentA = Shipment::create([
            'uuid' => 'shipment-A-uuid',
            'number' => '29УТ-A',
            'user_id' => $user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 6000,
        ]);

        $shipmentB = Shipment::create([
            'uuid' => 'shipment-B-uuid',
            'number' => '29УТ-B',
            'user_id' => $user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'USD',
            'total_amount' => 500,
        ]);

        $siA = ShipmentItem::create([
            'shipment_id' => $shipmentA->id,
            'product_id' => $product1->id,
            'quantity' => 2, 'price' => 3000,
            'auto_discount_percent' => 0, 'manual_discount_percent' => 0,
            'total' => 6000, 'subtotal' => 6000, 'vat_rate' => 20,
        ]);

        $siB = ShipmentItem::create([
            'shipment_id' => $shipmentB->id,
            'product_id' => $product2->id,
            'quantity' => 5, 'price' => 100,
            'auto_discount_percent' => 0, 'manual_discount_percent' => 0,
            'total' => 500, 'subtotal' => 500, 'vat_rate' => 20,
        ]);

        $return = ProductReturn::create([
            'uuid' => 'return-multi-001',
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 6300,
        ]);

        $return->items()->create([
            'shipment_item_id' => $siA->id, 'shipment_id' => $shipmentA->id,
            'product_id' => $product1->id, 'quantity' => 2, 'reason' => 'defective',
            'price' => 3000, 'subtotal' => 6000,
        ]);
        $return->items()->create([
            'shipment_item_id' => $siB->id, 'shipment_id' => $shipmentB->id,
            'product_id' => $product2->id, 'quantity' => 3, 'reason' => 'wrong_item',
            'price' => 100, 'subtotal' => 300,
        ]);

        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            $payload = $job->payload;
            $currencies = array_column($payload['items'], 'currency_code');
            $numbers = array_column($payload['items'], 'shipment_number');

            return count($payload['items']) === 2
                && in_array('RUB', $currencies, true)
                && in_array('USD', $currencies, true)
                && in_array('29УТ-A', $numbers, true)
                && in_array('29УТ-B', $numbers, true);
        });
    }

    #[Test]
    public function return_created_event_handles_user_without_erp_id(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create(['erp_id' => null]);

        $return = ProductReturn::create([
            'uuid' => 'return-no-erp-001',
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 0,
        ]);

        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            return $job->payload['partner_uuid'] === null
                && ! array_key_exists('order_uuid', $job->payload);
        });
    }
}
