<?php

namespace Tests\Feature\Services\Returns;

use App\Events\ReturnCreated;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReturnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReturnService;
    }

    protected function makeShipmentItem(User $user, float $price = 1500, int $quantity = 5, string $currency = 'RUB'): ShipmentItem
    {
        $product = Product::factory()->create();
        $shipment = Shipment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'number' => 'SHP-'.fake()->randomNumber(5),
            'user_id' => $user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => $currency,
            'total_amount' => $price * $quantity,
        ]);

        return ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'total' => $price * $quantity,
            'subtotal' => $price * $quantity,
            'vat_rate' => 20,
        ]);
    }

    #[Test]
    public function creates_return_with_price_snapshot_from_shipment_item(): void
    {
        Event::fake([ReturnCreated::class]);

        $user = User::factory()->create();
        $si = $this->makeShipmentItem($user, price: 1234.56, quantity: 4);

        $return = $this->service->createForUser($user, [
            'comment' => 'Тестовый возврат',
            'items' => [[
                'shipment_item_id' => $si->id,
                'quantity' => 2,
                'reason' => 'defective',
                'reason_comment' => 'брак',
            ]],
        ]);

        $this->assertInstanceOf(ProductReturn::class, $return);
        $this->assertSame($user->id, $return->user_id);
        $this->assertSame('pending_approval', $return->status->value);
        $this->assertEqualsWithDelta(2469.12, (float) $return->total_amount, 0.01);

        $item = $return->items()->first();
        $this->assertSame($si->id, $item->shipment_item_id);
        $this->assertSame($si->shipment_id, $item->shipment_id);
        $this->assertEqualsWithDelta(1234.56, (float) $item->price, 0.01);
        $this->assertEqualsWithDelta(2469.12, (float) $item->subtotal, 0.01);

        Event::assertDispatched(ReturnCreated::class);
    }

    #[Test]
    public function rejects_quantity_exceeding_available(): void
    {
        $user = User::factory()->create();
        $si = $this->makeShipmentItem($user, quantity: 3);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Доступно к возврату: 3');

        $this->service->createForUser($user, [
            'items' => [[
                'shipment_item_id' => $si->id,
                'quantity' => 5,
                'reason' => 'defective',
            ]],
        ]);
    }

    #[Test]
    public function rejects_shipment_not_belonging_to_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $si = $this->makeShipmentItem($owner);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Реализация не принадлежит пользователю.');

        $this->service->createForUser($intruder, [
            'items' => [[
                'shipment_item_id' => $si->id,
                'quantity' => 1,
                'reason' => 'defective',
            ]],
        ]);
    }

    #[Test]
    public function admin_can_create_return_for_another_user(): void
    {
        Event::fake([ReturnCreated::class]);

        $owner = User::factory()->create();
        $si = $this->makeShipmentItem($owner);

        $return = $this->service->createForAnyUser($owner, [
            'items' => [[
                'shipment_item_id' => $si->id,
                'quantity' => 1,
                'reason' => 'other',
            ]],
        ]);

        $this->assertSame($owner->id, $return->user_id);
    }

    #[Test]
    public function accumulates_available_quantity_across_existing_returns(): void
    {
        $user = User::factory()->create();
        $si = $this->makeShipmentItem($user, quantity: 5);

        ReturnItem::create([
            'return_id' => ProductReturn::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $user->id,
                'status' => 'in_reserve',
                'total_amount' => 0,
            ])->id,
            'shipment_item_id' => $si->id,
            'shipment_id' => $si->shipment_id,
            'product_id' => $si->product_id,
            'quantity' => 3,
            'price' => $si->price,
            'subtotal' => $si->price * 3,
            'reason' => 'defective',
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Доступно к возврату: 2');

        $this->service->createForUser($user, [
            'items' => [[
                'shipment_item_id' => $si->id,
                'quantity' => 3,
                'reason' => 'defective',
            ]],
        ]);
    }
}
