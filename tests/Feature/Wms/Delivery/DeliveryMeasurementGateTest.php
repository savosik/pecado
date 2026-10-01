<?php

namespace Tests\Feature\Wms\Delivery;

use App\Enums\DeliveryMethod;
use App\Models\Delivery\DeliveryShipment;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\GoodsIssuePackage;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Расчёт доставки и обмер грузовых мест расходного ордера (v16.14.0, топик Agent Hub №13).
 *
 * Правила: ордер старого формата (без обмера) считается как раньше — места вводит кладовщик;
 * `pending`, неизвестный способ и расхождение способа расчёт блокируют; `mixed` — нет:
 * ордер едет целиком, по всем местам.
 */
class DeliveryMeasurementGateTest extends DeliveryTestCase
{
    private User $client;

    private Order $order;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create();
        $this->order = Order::factory()->create([
            'user_id' => $this->client->id,
            'delivery_method' => DeliveryMethod::DELIVERY,
        ]);

        $this->shipment = $this->makeShipment($this->client);
        $this->shipment->items()->update(['order_uuid' => $this->order->uuid]);

        $this->fakeApiShip([
            '*/v1/calculator' => Http::response([
                'deliveryToDoor' => [[
                    'providerKey' => 'dpd',
                    'tariffs' => [['tariffId' => 300, 'tariffName' => 'Курьер', 'deliveryCost' => 1200]],
                ]],
                'deliveryToPoint' => [],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<Order>|null  $orders
     */
    private function goodsIssue(array $attrs, bool $measuredPlaces = true, ?array $orders = null): GoodsIssue
    {
        $issue = GoodsIssue::factory()->create(array_merge([
            'status' => GoodsIssue::STATUS_CHECKED,
            'date' => now(),
        ], $attrs));

        foreach ($orders ?? [$this->order] as $i => $order) {
            GoodsIssueItem::factory()->create([
                'goods_issue_id' => $issue->id,
                'line_number' => $i + 1,
                'order_uuid' => $order->uuid,
            ]);
        }

        GoodsIssuePackage::factory()->create([
            'goods_issue_id' => $issue->id,
            'uuid' => '00000000-0000-4000-a000-0000000013d1',
            'number' => 1,
            'package_type' => 'box',
            'weight' => $measuredPlaces ? 12.4 : null,
            'length' => $measuredPlaces ? 60 : null,
            'width' => $measuredPlaces ? 40 : null,
            'height' => $measuredPlaces ? 35 : null,
        ]);
        GoodsIssuePackage::factory()->create([
            'goods_issue_id' => $issue->id,
            'uuid' => '00000000-0000-4000-a000-0000000013d2',
            'number' => 2,
            'package_type' => 'pallet',
            'weight' => 218,
            'length' => 120,
            'width' => 80,
            'height' => 145,
        ]);

        return $issue;
    }

    private function delivery(): DeliveryShipment
    {
        $delivery = DeliveryShipment::factory()->create(['user_id' => $this->client->id]);
        $delivery->shipments()->sync([$this->shipment->id => ['amount' => 12000, 'weight' => 3000]]);
        $this->addPlace($delivery);

        return $delivery;
    }

    private function calculate(DeliveryShipment $delivery): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userWithRole('storekeeper'))
            ->postJson("/wms/deliveries/{$delivery->id}/calculate");
    }

    #[Test]
    #[TestDox('Ордер старого формата (без обмера) расчёт не блокирует — места вводит кладовщик')]
    public function legacy_issue_does_not_block(): void
    {
        $this->goodsIssue([]);

        $this->calculate($this->delivery())->assertOk()->assertJsonCount(1, 'tariffs');
    }

    #[Test]
    #[TestDox('Обмер не завершён — расчёта нет, перевозчик не вызывается')]
    public function pending_measurement_blocks(): void
    {
        $this->goodsIssue([
            'number' => 'УТ-00013001',
            'shipping_mode' => 'delivery',
            'measurement_required' => true,
            'measurement_state' => GoodsIssue::MEASUREMENT_PENDING,
        ], measuredPlaces: false);

        $response = $this->calculate($this->delivery())->assertStatus(422);

        $this->assertStringContainsString('Обмер мест по ордеру УТ-00013001 не завершён', $response->json('error'));
        Http::assertNothingSent();
    }

    #[Test]
    #[TestDox('Способ доставки 1С не определила — расчёта нет, «обратитесь к менеджеру»')]
    public function unknown_mode_blocks(): void
    {
        $this->goodsIssue([
            'shipping_mode' => null,
            'measurement_required' => true,
            'measurement_state' => GoodsIssue::MEASUREMENT_PENDING,
        ]);

        $response = $this->calculate($this->delivery())->assertStatus(422);

        $this->assertStringContainsString('обратитесь к менеджеру', $response->json('error'));
    }

    #[Test]
    #[TestDox('1С говорит «самовывоз», заказ на сайте — на доставку: расчёт заблокирован')]
    public function mode_mismatch_blocks(): void
    {
        $this->goodsIssue([
            'shipping_mode' => 'pickup',
            'measurement_required' => false,
            'measurement_state' => GoodsIssue::MEASUREMENT_NOT_REQUIRED,
        ]);

        $response = $this->calculate($this->delivery())->assertStatus(422);

        $this->assertStringContainsString('расходится', $response->json('error'));
    }

    #[Test]
    #[TestDox('Обмер завершён — расчёт идёт')]
    public function done_measurement_allows_calculation(): void
    {
        $this->goodsIssue([
            'shipping_mode' => 'delivery',
            'measurement_required' => true,
            'measurement_state' => GoodsIssue::MEASUREMENT_DONE,
            'measured_at' => now(),
            'measured_by' => 'Иванов И.И.',
        ]);

        $this->calculate($this->delivery())->assertOk();
    }

    #[Test]
    #[TestDox('Смешанный ордер расчёт не блокирует, в мастер отправки уходят все его места')]
    public function mixed_issue_is_calculated_by_all_places(): void
    {
        $pickup = Order::factory()->create([
            'user_id' => $this->client->id,
            'delivery_method' => DeliveryMethod::PICKUP,
        ]);

        $this->goodsIssue([
            'number' => 'УТ-00013002',
            'shipping_mode' => 'mixed',
            'measurement_required' => true,
            'measurement_state' => GoodsIssue::MEASUREMENT_DONE,
            'measured_at' => now(),
            'measured_by' => 'Иванов И.И.',
        ], orders: [$this->order, $pickup]);

        // Мастер отправки: реализация ещё свободна, места приходят из обмера.
        $props = $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/deliveries/create?shipment_ids[]='.$this->shipment->id)
            ->assertOk()
            ->viewData('page')['props'];

        $measurement = $props['preselected'][0]['goods_issue']['measurement'];

        $this->assertSame('ready', $measurement['verdict']);
        $this->assertFalse($measurement['blocks']);
        $this->assertStringContainsString('весь ордер', $measurement['message']);
        // Граммы и сантиметры — единицы формы отправки и перевозчика.
        $this->assertSame([12400, 218000], array_column($measurement['places'], 'weight'));
        $this->assertSame([35, 145], array_column($measurement['places'], 'height'));

        $this->calculate($this->delivery())->assertOk();
    }

    #[Test]
    #[TestDox('Выключатель APISHIP_MEASUREMENT_GATE возвращает расчёт по ручным местам')]
    public function gate_can_be_switched_off(): void
    {
        config()->set('services.apiship.measurement_gate', false);

        $this->goodsIssue([
            'shipping_mode' => 'delivery',
            'measurement_required' => true,
            'measurement_state' => GoodsIssue::MEASUREMENT_PENDING,
        ]);

        $this->calculate($this->delivery())->assertOk();
    }
}
