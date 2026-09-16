<?php

namespace Tests\Feature\Api\Client;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiOrdersReadTest extends ClientApiTestCase
{
    private function order(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
        ], $attrs));
    }

    #[Test]
    #[TestDox('Список заказов: только свои, курсорная пагинация без повторов, фильтр по статусу')]
    public function list_is_own_and_cursor_paginated(): void
    {
        foreach (range(1, 3) as $i) {
            $this->order(['number' => 'ORD-A-'.$i]);
        }
        $this->order(['number' => 'ORD-DONE', 'status' => OrderStatus::CLOSED]);
        Order::factory()->create(['user_id' => User::factory()->create()->id, 'number' => 'ORD-FOREIGN']);

        $first = $this->api('GET', '/orders?per_page=2')->assertOk();
        $first->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true);
        $second = $this->api('GET', '/orders?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

        $numbers = array_merge(array_column($first->json('data'), 'number'), array_column($second->json('data'), 'number'));
        $this->assertCount(4, array_unique($numbers));
        $this->assertNotContains('ORD-FOREIGN', $numbers);

        $this->api('GET', '/orders?status[]=closed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'ORD-DONE');
    }

    #[Test]
    #[TestDox('Карточка заказа по id, номеру и uuid; чужой заказ — 404; себестоимость не утекает')]
    public function card_by_any_identifier(): void
    {
        $product = Product::factory()->create(['cost_price' => 777.77]);
        $order = $this->order(['number' => 'ORD-2026-0001', 'erp_number' => '29УТ-014379']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);

        // Номер сайта, номер 1С (так его показывает список) и номер 1С без дефиса.
        foreach ([$order->id, 'ORD-2026-0001', '29УТ-014379', '29УТ014379', $order->uuid] as $identifier) {
            $response = $this->api('GET', '/orders/'.$identifier)->assertOk()
                ->assertJsonPath('data.id', $order->id)
                ->assertJsonPath('data.company.inn', '7707083893')
                ->assertJsonCount(1, 'data.items');
            $this->assertStringNotContainsString('777.77', $response->getContent());
            $this->assertStringNotContainsString('cost_price', $response->getContent());
        }

        $foreign = Order::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->api('GET', '/orders/'.$foreign->id)->assertStatus(404)->assertJsonPath('errors.0.code', 'not_found');
        $this->api('GET', '/orders/'.$foreign->uuid)->assertStatus(404);
    }

    #[Test]
    #[TestDox('orders/changes попадает в операцию журнала изменений, а не в карточку заказа')]
    public function changes_route_wins_over_order_card(): void
    {
        $this->api('GET', '/orders/changes')->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    #[Test]
    #[TestDox('Реализации: свои по списку и карточке, блок оплаты только при финансах, чужая — 404')]
    public function shipments_read(): void
    {
        $mine = Shipment::factory()->create(['user_id' => $this->client->id, 'currency_code' => 'RUB', 'status' => 'completed', 'erp_number' => '29УТ-003413', 'paid_amount' => 500]);
        Shipment::factory()->create(['user_id' => User::factory()->create()->id, 'erp_number' => 'ЧУЖОЙ-1']);

        config(['cabinet.finance_enabled' => false, 'cabinet.finance_pilot_user_ids' => '']);
        $row = $this->api('GET', '/shipments')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('29УТ-003413', $row['number']);
        $this->assertArrayNotHasKey('payment_status', $row);

        config(['cabinet.finance_enabled' => true]);
        $this->api('GET', '/shipments')->assertOk()->assertJsonStructure(['data' => [['payment_status']]]);

        $this->api('GET', '/shipments/'.$mine->id)->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->api('GET', '/shipments/29УТ003413')->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->api('GET', '/shipments/ЧУЖОЙ-1')->assertStatus(404);
    }

    #[Test]
    #[TestDox('Резервы: вне режима 403 с кодом, в режиме — список с items_version и reserved_until')]
    public function reserves_list_is_gated(): void
    {
        config(['order_reserve.enabled' => false]);
        $this->api('GET', '/reserves')->assertStatus(403)->assertJsonPath('errors.0.code', 'reserve_unavailable');

        config(['order_reserve.enabled' => true, 'order_reserve.canary' => '']);
        $this->client->update(['reserve_allowed' => true]);
        $this->order(['reserve' => true, 'reserved_until' => now()->addHours(20), 'items_version' => 3]);

        $this->api('GET', '/reserves')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.items_version', 3)
            ->assertJsonStructure(['data' => [['reserved_until']]]);
        $this->api('GET', '/me')->assertJsonPath('data.features.reserve', true);
    }
}
