<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Действия по уже переданной заявке: этикетка, пункты выдачи, вызов курьера, отмена.
 */
class DeliveryOperationsTest extends DeliveryTestCase
{
    #[Test]
    #[TestDox('Этикетка отдаётся редиректом на PDF из хранилища ApiShip')]
    public function label_redirects_to_pdf(): void
    {
        $this->fakeApiShip([
            '*/orders/labels' => Http::response([
                'url' => 'https://storage.apiship.ru/file/get?file=1234567_3.pdf',
                'failedOrders' => null,
            ], 200),
        ]);

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}/label")
            ->assertRedirect('https://storage.apiship.ru/file/get?file=1234567_3.pdf');

        $sent = $this->sentPayload('/orders/labels');
        $this->assertSame([(int) $delivery->apiship_order_id], $sent['orderIds']);
        $this->assertSame('pdf', $sent['format']);
    }

    #[Test]
    #[TestDox('До передачи заявки этикетки нет')]
    public function label_is_unavailable_before_submit(): void
    {
        $this->fakeApiShip();

        $delivery = DeliveryShipment::factory()->calculated()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}/label")
            ->assertRedirect();

        $this->assertNull($this->sentPayload('/orders/labels'));
    }

    #[Test]
    #[TestDox('Акт приёма-передачи отдаётся редиректом на PDF')]
    public function waybill_redirects_to_pdf(): void
    {
        $this->fakeApiShip([
            '*/orders/waybills' => Http::response([
                'url' => 'https://storage.apiship.ru/file/get?file=7654321_act.pdf',
                'failedOrders' => null,
            ], 200),
        ]);

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}/waybill")
            ->assertRedirect('https://storage.apiship.ru/file/get?file=7654321_act.pdf');

        $sent = $this->sentPayload('/orders/waybills');
        $this->assertSame([(int) $delivery->apiship_order_id], $sent['orderIds']);
        $this->assertSame('pdf', $sent['format']);
    }

    #[Test]
    #[TestDox('До передачи заявки акта нет')]
    public function waybill_is_unavailable_before_submit(): void
    {
        $this->fakeApiShip();

        $delivery = DeliveryShipment::factory()->calculated()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}/waybill")
            ->assertRedirect();

        $this->assertNull($this->sentPayload('/orders/waybills'));
    }

    #[Test]
    #[TestDox('Отказ перевозчика в документе доносится текстом, а не пустым редиректом')]
    public function waybill_failure_reports_the_error(): void
    {
        $this->fakeApiShip([
            '*/orders/waybills' => Http::response([
                'code' => 400,
                'message' => 'Bad Request',
                'description' => 'Перевозчик не формирует акт по этому тарифу',
            ], 400),
        ]);

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}/waybill")
            ->assertSessionHas('error');
    }

    #[Test]
    #[TestDox('Список пунктов выдачи фильтруется по городу получателя и перевозчику')]
    public function points_are_filtered_by_city_and_provider(): void
    {
        $this->fakeApiShip([
            '*/lists/points*' => Http::response([
                'rows' => [
                    ['id' => 'MSK1', 'name' => 'ПВЗ на Красносельской', 'address' => 'Москва, ул Нижняя Красносельская, 35'],
                    ['id' => '', 'name' => 'Битая строка без идентификатора', 'address' => ''],
                ],
            ], 200),
        ]);

        $delivery = DeliveryShipment::factory()->calculated()->create([
            'delivery_type' => DeliveryShipment::DELIVERY_TYPE_POINT,
        ]);

        $response = $this->actingAs($this->userWithRole('storekeeper'))
            ->getJson("/wms/deliveries/{$delivery->id}/points")
            ->assertOk();

        // Строка без id отсеивается: выбрать такой пункт всё равно нельзя.
        $this->assertCount(1, $response->json('points'));
        $this->assertSame('MSK1', $response->json('points.0.id'));

        $url = collect(Http::recorded())
            ->map(fn (array $pair): string => $pair[0]->url())
            ->first(fn (string $url): bool => str_contains($url, '/lists/points'));

        $this->assertStringContainsString('filter=city=Москва;providerKey=cdek', urldecode($url));
    }

    #[Test]
    #[TestDox('Вызов курьера уходит с адресом склада и весом груза')]
    public function courier_call_uses_sender_address(): void
    {
        $this->fakeApiShip([
            '*/courierCall' => Http::response(['id' => 55], 200),
        ]);

        $delivery = DeliveryShipment::factory()->submitted()->create();
        $this->addPlace($delivery, weight: 4100);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/courier", [
                'date' => now()->addDay()->format('Y-m-d'),
                'time_start' => '10:00',
                'time_end' => '18:00',
            ])
            ->assertSessionHas('success');

        $sent = $this->sentPayload('/courierCall');

        $this->assertSame('cdek', $sent['providerKey']);
        $this->assertSame(4100, $sent['weight']);
        $this->assertSame('Тюмень', $sent['city']);
        $this->assertSame([(int) $delivery->apiship_order_id], $sent['orderIds']);
    }

    #[Test]
    #[TestDox('Вызов курьера на прошедшую дату не проходит валидацию')]
    public function courier_call_rejects_past_date(): void
    {
        $this->fakeApiShip();

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/courier", [
                'date' => now()->subDay()->format('Y-m-d'),
                'time_start' => '10:00',
                'time_end' => '18:00',
            ])
            ->assertSessionHasErrors('date');
    }

    #[Test]
    #[TestDox('Отмена пишет запись в журнал статусов и освобождает реализации')]
    public function cancel_releases_documents(): void
    {
        $this->fakeApiShip([
            '*/cancel' => Http::response(['orderId' => 4561111, 'canceled' => '2026-08-09T10:00:00+03:00'], 200),
        ]);

        $shipment = $this->makeShipment();
        $delivery = DeliveryShipment::factory()->submitted()->create(['user_id' => $shipment->user_id]);
        $delivery->shipments()->attach($shipment->id, ['amount' => 12000, 'weight' => 3000]);

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->post("/wms/deliveries/{$delivery->id}/cancel")
            ->assertSessionHas('success');

        $delivery->refresh();

        $this->assertSame('cancelled', $delivery->status->value);
        $this->assertSame('manual', $delivery->statusHistories()->first()->source);

        // Отменённая отправка реализации не держит — груз собирают заново.
        $this->assertNotContains(
            $shipment->id,
            app(\App\Services\Delivery\DeliveryShipmentBuilder::class)->lockedShipmentIds(),
        );
    }

    #[Test]
    #[TestDox('Отказ перевозчика в отмене оставляет отправку в прежнем статусе')]
    public function failed_cancel_keeps_status(): void
    {
        $this->fakeApiShip([
            '*/cancel' => Http::response(['message' => 'Заказ уже в пути'], 409),
        ]);

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->post("/wms/deliveries/{$delivery->id}/cancel")
            ->assertSessionHas('error');

        $delivery->refresh();

        $this->assertSame('submitted', $delivery->status->value);
        $this->assertStringContainsString('Заказ уже в пути', $delivery->last_error);
    }

    #[Test]
    #[TestDox('Черновик удаляется, переданная заявка — нет')]
    public function only_drafts_can_be_deleted(): void
    {
        $this->fakeApiShip();

        $draft = DeliveryShipment::factory()->create();
        $submitted = DeliveryShipment::factory()->submitted()->create();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->delete("/wms/deliveries/{$draft->id}")->assertRedirect('/wms/deliveries');
        $this->assertSoftDeleted('delivery_shipments', ['id' => $draft->id]);

        $this->actingAs($storekeeper)->delete("/wms/deliveries/{$submitted->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('delivery_shipments', ['id' => $submitted->id]);
    }

    #[Test]
    #[TestDox('Журнал вызовов ApiShip виден начальнику склада и скрыт от кладовщика')]
    public function api_log_is_visible_only_to_warehouse_head(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->get("/wms/deliveries/{$delivery->id}")
            ->assertInertia(fn ($page) => $page->where('apiLog', []));

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}")
            ->assertInertia(fn ($page) => $page->where('apiLog', null));
    }
}
