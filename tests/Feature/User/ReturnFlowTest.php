<?php

namespace Tests\Feature\User;

use App\Events\ReturnCreated;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function makeShipmentWithItem(User $user): array
    {
        $product = Product::factory()->create();
        $shipment = Shipment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => '29УТ-FLOW-001',
            'user_id' => $user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
        ]);
        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 200,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'total' => 1000,
            'subtotal' => 1000,
            'vat_rate' => 20,
        ]);

        return [$shipment, $item];
    }

    #[Test]
    public function cabinet_search_shipments_returns_only_own_shipments(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        [$ownShipment] = $this->makeShipmentWithItem($user);
        Shipment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'OTHER-001',
            'user_id' => $otherUser->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 500,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('cabinet.returns.search-shipments', ['query' => '']));

        $response->assertOk();
        $numbers = collect($response->json())->pluck('number')->all();
        $this->assertContains($ownShipment->number, $numbers);
        $this->assertNotContains('OTHER-001', $numbers);
    }

    #[Test]
    public function cabinet_shipment_items_returns_available_quantity(): void
    {
        $user = User::factory()->create();
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem($user);

        $response = $this->actingAs($user)
            ->getJson(route('cabinet.returns.shipment-items', ['shipment_id' => $shipment->id]));

        $response->assertOk()
            ->assertJsonPath('shipment.number', '29УТ-FLOW-001')
            ->assertJsonPath('items.0.shipment_item_id', $shipmentItem->id)
            ->assertJsonPath('items.0.available_quantity', 5)
            ->assertJsonPath('items.0.currency_code', 'RUB');

        $this->assertEqualsWithDelta(200.0, (float) $response->json('items.0.price'), 0.01);
    }

    #[Test]
    public function cabinet_store_creates_return_and_dispatches_event(): void
    {
        Event::fake([ReturnCreated::class]);

        $user = User::factory()->create();
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem($user);

        $response = $this->actingAs($user)->post(route('cabinet.returns.store'), [
            'comment' => 'Возврат через flow-тест',
            'items' => [[
                'shipment_item_id' => $shipmentItem->id,
                'quantity' => 2,
                'reason' => 'defective',
                'reason_comment' => 'брак',
            ]],
        ]);

        $response->assertRedirect();

        $return = ProductReturn::firstOrFail();
        $this->assertSame($user->id, $return->user_id);
        $this->assertCount(1, $return->items);
        $item = $return->items->first();
        $this->assertSame($shipmentItem->id, $item->shipment_item_id);
        $this->assertSame($shipment->id, $item->shipment_id);
        $this->assertEqualsWithDelta(200.0, (float) $item->price, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $item->subtotal, 0.01);

        Event::assertDispatched(ReturnCreated::class);
    }

    #[Test]
    public function cabinet_store_rejects_shipment_not_belonging_to_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [, $shipmentItem] = $this->makeShipmentWithItem($other);

        $response = $this->actingAs($user)->from(route('cabinet.returns.create'))->post(route('cabinet.returns.store'), [
            'items' => [[
                'shipment_item_id' => $shipmentItem->id,
                'quantity' => 1,
                'reason' => 'defective',
            ]],
        ]);

        $response->assertRedirect(route('cabinet.returns.create'));
        $this->assertSame(0, ProductReturn::count());
    }
}
