<?php

namespace Tests\Feature\Api\Client;

use App\Enums\ReturnReason;
use App\Events\ReturnCreated;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiReturnsTest extends ClientApiTestCase
{
    private function shipment(User $owner, array $attrs = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'user_id' => $owner->id,
            'currency_code' => 'RUB',
            'status' => 'completed',
        ], $attrs));
    }

    #[Test]
    #[TestDox('Основания: реализации по поиску и строки с доступным к возврату количеством; чужая реализация — 404')]
    public function bases_are_own_shipments_only(): void
    {
        $product = Product::factory()->create(['name' => 'Масло чайное', 'sku' => 'OIL-1']);
        $shipment = $this->shipment($this->client, ['number' => 'УТ-000123']);
        $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 5, 'price' => 100]);
        ReturnItem::factory()->create([
            'return_id' => ProductReturn::factory()->create(['user_id' => $this->client->id])->id,
            'shipment_item_id' => $item->id,
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $foreign = $this->shipment(User::factory()->create());

        $this->api('GET', '/returns/shipments?q=УТ000123')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shipment->id)
            ->assertJsonPath('data.0.open_returns_count', 1);

        $this->api('GET', '/returns/shipments?q=чайное')->assertOk()
            ->assertJsonPath('data.0.match_source', 'composition');

        $this->api('GET', '/returns/shipment-items?shipment='.$shipment->id)->assertOk()
            ->assertJsonPath('data.items.0.shipped_quantity', 5)
            ->assertJsonPath('data.items.0.already_returned', 2)
            ->assertJsonPath('data.items.0.available_quantity', 3);

        $this->api('GET', '/returns/shipment-items?shipment='.$foreign->id)->assertStatus(404);
    }

    #[Test]
    #[TestDox('Создание: возврат по строкам, лимит доступного, идемпотентный повтор, карточка и список')]
    public function create_and_read_returns(): void
    {
        Event::fake([ReturnCreated::class]);
        $product = Product::factory()->create();
        $shipment = $this->shipment($this->client);
        $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 3, 'price' => 50]);

        $this->api('POST', '/returns', ['items' => [['shipment_item_id' => $item->id, 'quantity' => 5, 'reason' => 'defective']]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'business_rule')
            ->assertJsonPath('errors.0.message', 'Доступно к возврату: 3.');

        $this->api('POST', '/returns', ['items' => [['shipment_item_id' => $item->id, 'quantity' => 1, 'reason' => 'нет']]])
            ->assertStatus(422)->assertJsonPath('errors.0.field', 'items.0.reason');

        $payload = ['comment' => 'Брак', 'items' => [['shipment_item_id' => $item->id, 'quantity' => 2, 'reason' => ReturnReason::DEFECTIVE->value, 'reason_comment' => 'Течёт']]];
        $created = $this->api('POST', '/returns', $payload, ['Idempotency-Key' => 'ret-1'])->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.total_amount', 100)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.reason_label', 'Бракованный товар');
        $id = $created->json('data.id');

        $this->api('POST', '/returns', $payload, ['Idempotency-Key' => 'ret-1'])->assertJsonPath('data.id', $id);
        $this->assertSame(1, ProductReturn::count());
        Event::assertDispatchedTimes(ReturnCreated::class, 1);

        $this->api('GET', "/returns/{$id}")->assertOk()->assertJsonPath('data.comment', 'Брак')->assertJsonPath('data.items.0.shipment.id', $shipment->id);
        $this->api('GET', '/returns?status[]=pending_approval')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.primary_reason', 'defective');
        $this->api('GET', '/returns?status[]=completed')->assertOk()->assertJsonCount(0, 'data');

        $foreign = ProductReturn::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->api('GET', "/returns/{$foreign->id}")->assertStatus(404);
    }

    #[Test]
    #[TestDox('Чужая строка реализации в заявке — отказ, возврат не создаётся')]
    public function foreign_shipment_item_is_rejected(): void
    {
        $foreign = $this->shipment(User::factory()->create());
        $item = ShipmentItem::factory()->create(['shipment_id' => $foreign->id, 'quantity' => 3]);

        $this->api('POST', '/returns', ['items' => [['shipment_item_id' => $item->id, 'quantity' => 1, 'reason' => 'other']]])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'forbidden');

        $this->assertSame(0, ProductReturn::count());
    }
}
