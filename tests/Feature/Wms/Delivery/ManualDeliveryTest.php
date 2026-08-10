<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Ручная отметка «уже отправлено».
 *
 * Систему внедряют задним числом, и часть перевозчиков к ApiShip не подключена
 * вовсе. Без этой ветки такие реализации вечно висели бы в кандидатах.
 */
class ManualDeliveryTest extends DeliveryTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $shipmentIds, array $overrides = []): array
    {
        return array_merge([
            'shipment_ids' => $shipmentIds,
            'carrier_name' => 'Деловые Линии',
            'provider_number' => 'DL-77712345',
            'shipped_at' => now()->subDays(3)->format('Y-m-d'),
            'status' => 'in_transit',
            'comment' => 'Заявку делали на сайте ТК',
            'delivery_cost' => 1500,
        ], $overrides);
    }

    #[Test]
    #[TestDox('Отметка создаёт отправку без обращения к ApiShip')]
    public function marking_creates_delivery_without_api_call(): void
    {
        Http::fake();

        $client = User::factory()->create();
        $first = $this->makeShipment($client, weightKg: 1.5, quantity: 2);
        $second = $this->makeShipment($client, weightKg: 0.5, quantity: 1);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$first->id, $second->id]))
            ->assertSessionHas('success');

        Http::assertNothingSent();

        $delivery = DeliveryShipment::query()->firstOrFail();

        $this->assertTrue($delivery->is_manual);
        $this->assertSame('in_transit', $delivery->status->value);
        $this->assertSame('Деловые Линии', $delivery->carrier_name);
        $this->assertSame('DL-77712345', $delivery->provider_number);
        $this->assertNull($delivery->apiship_order_id);
        $this->assertSame(2, $delivery->shipments()->count());
        // 1.5 кг × 2 + 0.5 кг × 1 = 3.5 кг
        $this->assertSame(3500, $delivery->calculated_weight);
        $this->assertEquals(1500, $delivery->delivery_cost);
        $this->assertSame(now()->subDays(3)->format('Y-m-d'), $delivery->submitted_at->format('Y-m-d'));
    }

    #[Test]
    #[TestDox('Отмеченные реализации уходят из списка кандидатов')]
    public function marked_shipments_leave_the_candidate_list(): void
    {
        $client = User::factory()->create();
        $shipped = $this->makeShipment($client);
        $pending = $this->makeShipment($client);

        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipped->id]))
            ->assertSessionHas('success');

        $this->actingAs($storekeeper);
        $props = $this->inertiaProps('/wms/delivery-candidates');

        $this->assertSame([$pending->id], array_column(data_get($props, 'clients.0.shipments'), 'id'));
    }

    #[Test]
    #[TestDox('Ручная отправка попадает в журнал с пометкой и треком')]
    public function manual_delivery_appears_in_the_journal(): void
    {
        $shipment = $this->makeShipment();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipment->id]));

        $this->actingAs($storekeeper);
        $props = $this->inertiaProps('/wms/deliveries');

        $row = data_get($props, 'deliveries.data.0');

        $this->assertTrue($row['is_manual']);
        $this->assertSame('Деловые Линии', $row['provider_key']);
        $this->assertSame('DL-77712345', $row['provider_number']);
    }

    #[Test]
    #[TestDox('В журнале ведётся запись о ручной отметке')]
    public function status_history_records_the_manual_entry(): void
    {
        $shipment = $this->makeShipment();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipment->id]));

        $history = DeliveryShipment::query()->firstOrFail()->statusHistories()->first();

        $this->assertSame('manual', $history->source);
        $this->assertStringContainsString('вручную', $history->status_name);
    }

    #[Test]
    #[TestDox('Занятую реализацию повторно отметить нельзя')]
    public function busy_shipment_cannot_be_marked_twice(): void
    {
        $shipment = $this->makeShipment();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipment->id]));
        $this->actingAs($storekeeper)
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipment->id]))
            ->assertSessionHas('error');

        $this->assertSame(1, DeliveryShipment::query()->count());
    }

    #[Test]
    #[TestDox('Реализации разных клиентов одной отметкой не проходят')]
    public function different_clients_are_rejected(): void
    {
        $first = $this->makeShipment();
        $second = $this->makeShipment();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$first->id, $second->id]))
            ->assertSessionHas('error');

        $this->assertSame(0, DeliveryShipment::query()->count());
    }

    #[Test]
    #[TestDox('Дата отправки в будущем и пустой перевозчик не проходят валидацию')]
    public function validation_guards_the_form(): void
    {
        $shipment = $this->makeShipment();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipment->id], [
                'carrier_name' => '',
                'shipped_at' => now()->addDay()->format('Y-m-d'),
                'status' => 'draft',
                'tracking_url' => 'не ссылка',
            ]))
            ->assertSessionHasErrors(['carrier_name', 'shipped_at', 'status', 'tracking_url']);

        $this->assertSame(0, DeliveryShipment::query()->count());
    }

    #[Test]
    #[TestDox('Стоимость ручной отправки попадает в сводку за месяц')]
    public function manual_cost_counts_in_monthly_total(): void
    {
        $shipment = $this->makeShipment();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)
            ->post('/wms/delivery-candidates/mark-shipped', $this->payload([$shipment->id], [
                'shipped_at' => now()->format('Y-m-d'),
                'delivery_cost' => 2400,
            ]));

        $this->actingAs($storekeeper);
        $props = $this->inertiaProps('/wms/deliveries');

        $this->assertEquals(2400, data_get($props, 'stats.cost_this_month'));
    }
}
