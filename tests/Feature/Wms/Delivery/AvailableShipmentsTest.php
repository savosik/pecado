<?php

namespace Tests\Feature\Wms\Delivery;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Delivery\DeliveryShipment;
use App\Models\GoodsIssue;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Список реализаций в мастере отправки.
 *
 * Проверяем именно обогащение: голый номер реализации кладовщику бесполезен,
 * и если подсказки перестанут доезжать, выбор груза снова превратится в угадайку.
 */
class AvailableShipmentsTest extends DeliveryTestCase
{
    /**
     * Заказ, реализация по нему и (опционально) расходный ордер.
     */
    private function makeLinkedShipment(
        User $client,
        string $orderNumber = 'ЗАК-100',
        DeliveryMethod $method = DeliveryMethod::DELIVERY,
        ?string $address = 'г Москва, ул Ленина, д 1',
        ?string $goodsIssueStatus = null,
    ): Shipment {
        $orderUuid = (string) Str::uuid();

        Order::factory()->create([
            'uuid' => $orderUuid,
            'user_id' => $client->id,
            'erp_number' => $orderNumber,
            'delivery_method' => $method,
            'delivery_address' => $address,
        ]);

        $shipment = Shipment::factory()->create([
            'user_id' => $client->id,
            'erp_number' => 'РЕА-'.fake()->unique()->numberBetween(1000, 9999),
            'total_amount' => 12000,
        ]);

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'order_uuid' => $orderUuid,
            'quantity' => 1,
            'price' => 12000,
        ]);

        if ($goodsIssueStatus !== null) {
            $goodsIssue = GoodsIssue::create([
                'uuid' => (string) Str::uuid(),
                'number' => 'РО-777',
                'date' => now(),
                'status' => $goodsIssueStatus,
                'user_id' => $client->id,
                'packages_count' => 2,
            ]);

            $goodsIssue->items()->create([
                'line_number' => 1,
                'product_uuid' => (string) Str::uuid(),
                'product_name' => 'Товар',
                'order_uuid' => $orderUuid,
                'quantity' => 1,
            ]);
        }

        return $shipment->fresh();
    }

    #[Test]
    #[TestDox('Реализации сгруппированы по клиентам, внутри группы — от свежих к старым')]
    public function shipments_are_grouped_by_client_in_reverse_chronology(): void
    {
        $first = User::factory()->create(['name' => 'ООО Первый']);
        $second = User::factory()->create(['name' => 'ООО Второй']);

        $this->makeShipment($first)->update(['date' => '2026-08-01']);
        $old = $this->makeShipment($first);
        $old->update(['date' => '2026-07-01']);
        $this->makeShipment($second)->update(['date' => '2026-08-05']);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $clients = data_get($response, 'clients');

        $this->assertCount(2, $clients);
        // Клиент со свежей отгрузкой — первым.
        $this->assertSame('ООО Второй', $clients[0]['title']);
        $this->assertSame('ООО Первый', $clients[1]['title']);
        $this->assertSame(2, $clients[1]['shipments_count']);

        $dates = array_column($clients[1]['shipments'], 'date');
        $this->assertSame(['2026-08-01', '2026-07-01'], $dates);
    }

    #[Test]
    #[TestDox('Строка несёт способ доставки и адрес из заказа')]
    public function row_carries_delivery_method_and_address_from_order(): void
    {
        $client = User::factory()->create();
        $this->makeLinkedShipment($client);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $row = data_get($response, 'clients.0.shipments.0');

        $this->assertSame('Доставка', $row['delivery_method_label']);
        $this->assertSame('г Москва, ул Ленина, д 1', $row['delivery_address']);
        $this->assertSame('ЗАК-100', $row['orders'][0]['number']);
    }

    #[Test]
    #[TestDox('Самовывоз уходит на отдельную вкладку, доставка остаётся в основной')]
    public function pickup_shipments_go_to_a_separate_tab(): void
    {
        $client = User::factory()->create();
        $delivery = $this->makeLinkedShipment($client, 'ЗАК-100', DeliveryMethod::DELIVERY);
        $pickup = $this->makeLinkedShipment($client, 'ЗАК-200', DeliveryMethod::PICKUP, null);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $this->assertSame([$delivery->id], array_column(data_get($response, 'clients.0.shipments'), 'id'));
        $this->assertSame([$pickup->id], array_column(data_get($response, 'pickupClients.0.shipments'), 'id'));
        $this->assertSame('delivery', data_get($response, 'clients.0.shipments.0.delivery_kind'));
        $this->assertSame('pickup', data_get($response, 'pickupClients.0.shipments.0.delivery_kind'));
    }

    #[Test]
    #[TestDox('Смешанный и неизвестный способ остаются в основной вкладке — их надо разобрать, а не прятать')]
    public function mixed_and_unknown_stay_in_the_main_tab(): void
    {
        $client = User::factory()->create();

        // Реализация без заказов вовсе — способ неизвестен.
        $unknown = $this->makeShipment($client);

        // Реализация с самовывозом и доставкой одновременно.
        $mixed = $this->makeLinkedShipment($client, 'ЗАК-300', DeliveryMethod::PICKUP, null);
        $deliveryUuid = (string) Str::uuid();
        Order::factory()->create([
            'uuid' => $deliveryUuid,
            'user_id' => $client->id,
            'erp_number' => 'ЗАК-301',
            'delivery_method' => DeliveryMethod::DELIVERY,
            'delivery_address' => 'г Москва, ул Ленина, д 1',
        ]);
        ShipmentItem::factory()->create([
            'shipment_id' => $mixed->id,
            'order_uuid' => $deliveryUuid,
            'quantity' => 1,
            'price' => 100,
        ]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $ids = array_column(data_get($response, 'clients.0.shipments'), 'id');

        $this->assertContains($unknown->id, $ids);
        $this->assertContains($mixed->id, $ids);
        $this->assertSame([], data_get($response, 'pickupClients'));
    }

    #[Test]
    #[TestDox('Разные способы доставки в заказах не схлопываются в один')]
    public function conflicting_delivery_methods_are_not_merged(): void
    {
        $client = User::factory()->create();
        $shipment = $this->makeLinkedShipment($client, 'ЗАК-100', DeliveryMethod::DELIVERY, 'г Москва, ул Ленина, д 1');

        // Вторая позиция той же реализации — из заказа с самовывозом.
        $pickupUuid = (string) Str::uuid();
        Order::factory()->create([
            'uuid' => $pickupUuid,
            'user_id' => $client->id,
            'erp_number' => 'ЗАК-200',
            'delivery_method' => DeliveryMethod::PICKUP,
            'delivery_address' => null,
        ]);
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'order_uuid' => $pickupUuid,
            'quantity' => 1,
            'price' => 500,
        ]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $row = data_get($response, 'clients.0.shipments.0');

        // Способы разошлись — сводного значения нет, кладовщик смотрит список заказов.
        $this->assertNull($row['delivery_method_label']);
        $this->assertCount(2, $row['orders']);
    }

    #[Test]
    #[TestDox('Состояние сборки подтягивается из расходного ордера по общему заказу')]
    public function goods_issue_status_is_attached(): void
    {
        $client = User::factory()->create();
        $this->makeLinkedShipment($client, goodsIssueStatus: GoodsIssue::STATUS_TO_SHIP);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $goodsIssue = data_get($response, 'clients.0.shipments.0.goods_issue');

        $this->assertSame('РО-777', $goodsIssue['number']);
        $this->assertSame('К отгрузке', $goodsIssue['status_label']);
        $this->assertSame('teal', $goodsIssue['status_color']);
        $this->assertFalse($goodsIssue['is_shipped']);
        $this->assertSame(2, $goodsIssue['packages_count']);
    }

    #[Test]
    #[TestDox('Без расходного ордера строка не ломается')]
    public function missing_goods_issue_is_null(): void
    {
        $this->makeShipment();

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $this->assertNull(data_get($response, 'clients.0.shipments.0.goods_issue'));
    }

    #[Test]
    #[TestDox('След отменённой отправки виден в строке')]
    public function cancelled_delivery_leaves_a_trace(): void
    {
        $shipment = $this->makeShipment();

        $cancelled = DeliveryShipment::factory()->create([
            'user_id' => $shipment->user_id,
            'status' => \App\Enums\Delivery\DeliveryShipmentStatus::CANCELLED,
            'provider_key' => 'cdek',
        ]);
        $cancelled->shipments()->attach($shipment->id, ['amount' => 12000, 'weight' => 3000]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $previous = data_get($response, 'clients.0.shipments.0.previous_delivery');

        $this->assertSame($cancelled->fresh()->number, $previous['number']);
        $this->assertSame('Отменена', $previous['status_label']);
        $this->assertSame('cdek', $previous['provider_key']);
    }

    #[Test]
    #[TestDox('Строка несёт статусы связанных заказов')]
    public function row_carries_order_statuses(): void
    {
        $client = User::factory()->create();
        $shipment = $this->makeLinkedShipment($client);

        Order::query()->where('erp_number', 'ЗАК-100')
            ->update(['status' => OrderStatus::READY_FOR_SHIPMENT]);

        // Второй заказ с тем же статусом — в сводке они схлопываются со счётчиком.
        $secondUuid = (string) Str::uuid();
        Order::factory()->create([
            'uuid' => $secondUuid,
            'user_id' => $client->id,
            'erp_number' => 'ЗАК-101',
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'delivery_method' => DeliveryMethod::DELIVERY,
            'delivery_address' => 'г Москва, ул Ленина, д 1',
        ]);
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'order_uuid' => $secondUuid,
            'quantity' => 1,
            'price' => 100,
        ]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $statuses = data_get($response, 'clients.0.shipments.0.order_statuses');

        $this->assertCount(1, $statuses);
        $this->assertSame('Готов к отгрузке', $statuses[0]['label']);
        $this->assertSame(2, $statuses[0]['count']);
    }

    #[Test]
    #[TestDox('Фильтр по статусу заказа отсекает лишнее ещё в SQL')]
    public function order_status_filter_narrows_the_query(): void
    {
        $client = User::factory()->create();
        $ready = $this->makeLinkedShipment($client, 'ЗАК-100');
        $this->makeLinkedShipment($client, 'ЗАК-200');

        Order::query()->where('erp_number', 'ЗАК-100')->update(['status' => OrderStatus::READY_FOR_SHIPMENT]);
        Order::query()->where('erp_number', 'ЗАК-200')->update(['status' => OrderStatus::PENDING_APPROVAL]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates?order_statuses[]=ready_for_shipment');

        $this->assertSame([$ready->id], array_column(data_get($response, 'clients.0.shipments'), 'id'));
        $this->assertSame(1, data_get($response, 'meta.matched'));
    }

    #[Test]
    #[TestDox('Фильтр по статусу расходного ордера отсекает лишнее')]
    public function goods_issue_status_filter_narrows_the_query(): void
    {
        $client = User::factory()->create();
        $picked = $this->makeLinkedShipment($client, 'ЗАК-100', goodsIssueStatus: GoodsIssue::STATUS_TO_SHIP);
        $this->makeLinkedShipment($client, 'ЗАК-200');

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates?goods_issue_statuses[]=to_ship');

        $this->assertSame([$picked->id], array_column(data_get($response, 'clients.0.shipments'), 'id'));
    }

    #[Test]
    #[TestDox('Группировка по адресу собирает разных клиентов в одну группу')]
    public function grouping_by_address_merges_clients(): void
    {
        $first = User::factory()->create(['name' => 'ООО Первый']);
        $second = User::factory()->create(['name' => 'ООО Второй']);

        $this->makeLinkedShipment($first, 'ЗАК-100', DeliveryMethod::DELIVERY, 'г Москва, ул Ленина, д 1');
        $this->makeLinkedShipment($second, 'ЗАК-200', DeliveryMethod::DELIVERY, 'г Москва, ул Ленина, д 1');

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates?group_by=address');

        $groups = data_get($response, 'clients');

        $this->assertCount(1, $groups);
        $this->assertSame('г Москва, ул Ленина, д 1', $groups[0]['title']);
        $this->assertSame(2, $groups[0]['shipments_count']);
        // Группа смешивает клиентов — блокировка выбора работает построчно.
        $this->assertNull($groups[0]['user_id']);
        $this->assertStringContainsString('и ещё 1', $groups[0]['subtitle']);
    }

    #[Test]
    #[TestDox('Группировка по дате разводит реализации по дням')]
    public function grouping_by_date_splits_by_day(): void
    {
        $client = User::factory()->create();
        $this->makeShipment($client)->update(['date' => '2026-08-01']);
        $this->makeShipment($client)->update(['date' => '2026-08-05']);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates?group_by=date');

        $this->assertSame(['05.08.2026', '01.08.2026'], array_column(data_get($response, 'clients'), 'title'));
    }

    #[Test]
    #[TestDox('Сортировка внутри группы переключается')]
    public function row_sort_is_applied(): void
    {
        $client = User::factory()->create();
        $cheap = $this->makeShipment($client);
        $cheap->update(['date' => '2026-08-01', 'total_amount' => 100]);
        $rich = $this->makeShipment($client);
        $rich->update(['date' => '2026-07-01', 'total_amount' => 900000]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $byDate = $this->inertiaProps('/wms/delivery-candidates?row_sort=date_asc');
        $this->assertSame([$rich->id, $cheap->id], array_column(data_get($byDate, 'clients.0.shipments'), 'id'));

        $this->actingAs($this->userWithRole('storekeeper'));
        $byAmount = $this->inertiaProps('/wms/delivery-candidates?row_sort=amount_desc');
        $this->assertSame([$rich->id, $cheap->id], array_column(data_get($byAmount, 'clients.0.shipments'), 'id'));
    }

    #[Test]
    #[TestDox('Скрытая реализация уходит из списка и возвращается по флагу')]
    public function hidden_shipment_disappears_and_comes_back(): void
    {
        $client = User::factory()->create();
        $visible = $this->makeShipment($client);
        $hidden = $this->makeShipment($client);

        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)
            ->post('/wms/delivery-candidates/hide', [
                'shipment_id' => $hidden->id,
                'hidden' => true,
                'reason' => 'Увезли своей машиной',
            ])
            ->assertRedirect();

        $list = $this->inertiaProps('/wms/delivery-candidates');
        $this->assertSame([$visible->id], array_column(data_get($list, 'clients.0.shipments'), 'id'));
        $this->assertSame(1, data_get($list, 'meta.hidden_count'));

        $onlyHidden = $this->inertiaProps('/wms/delivery-candidates?show_hidden=1');
        $this->assertSame([$hidden->id], array_column(data_get($onlyHidden, 'clients.0.shipments'), 'id'));
        $this->assertSame('Увезли своей машиной', data_get($onlyHidden, 'clients.0.shipments.0.hidden.reason'));

        $this->actingAs($storekeeper)
            ->post('/wms/delivery-candidates/hide', ['shipment_id' => $hidden->id, 'hidden' => false])
            ->assertRedirect();

        $restored = $this->inertiaProps('/wms/delivery-candidates');
        $this->assertCount(2, data_get($restored, 'clients.0.shipments'));
    }

    #[Test]
    #[TestDox('Фильтр по адресу работает по подстроке')]
    public function address_filter_matches_substring(): void
    {
        $client = User::factory()->create();
        $moscow = $this->makeLinkedShipment($client, 'ЗАК-100', DeliveryMethod::DELIVERY, 'г Москва, ул Ленина, д 1');
        $this->makeLinkedShipment($client, 'ЗАК-200', DeliveryMethod::DELIVERY, 'г Казань, ул Баумана, д 5');

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates?address=ленина');

        $this->assertSame([$moscow->id], array_column(data_get($response, 'clients.0.shipments'), 'id'));
    }

    #[Test]
    #[TestDox('Оплата в списке не показывается — складу она не нужна')]
    public function payment_data_is_not_exposed(): void
    {
        $this->makeShipment();

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $row = data_get($response, 'clients.0.shipments.0');

        $this->assertArrayNotHasKey('payment_status', $row);
        $this->assertArrayNotHasKey('unpaid_amount', $row);
    }

    #[Test]
    #[TestDox('Реализации активных отправок в списке не показываются')]
    public function shipments_of_active_deliveries_are_hidden(): void
    {
        $shipment = $this->makeShipment();

        $active = DeliveryShipment::factory()->submitted()->create(['user_id' => $shipment->user_id]);
        $active->shipments()->attach($shipment->id, ['amount' => 12000, 'weight' => 3000]);

        $this->actingAs($this->userWithRole('storekeeper'));
        $response = $this->inertiaProps('/wms/delivery-candidates');

        $this->assertSame([], data_get($response, 'clients'));
    }
}
