<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Путь отправки: сборка из реализаций → расчёт → передача заявки в ТК.
 */
class DeliveryShipmentFlowTest extends DeliveryTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $shipmentIds, array $overrides = []): array
    {
        return array_merge([
            'shipment_ids' => $shipmentIds,
            'delivery_type' => 1,
            'pickup_type' => 1,
            'places' => [
                ['weight' => 3200, 'length' => 40, 'width' => 30, 'height' => 20],
            ],
            'recipient' => [
                'contactName' => 'Иван Петров',
                'phone' => '+79000000000',
                'countryCode' => 'RU',
                'region' => 'г Москва',
                'city' => 'Москва',
                'street' => 'ул Нижняя Красносельская',
                'house' => '35',
                'index' => '105066',
            ],
        ], $overrides);
    }

    #[Test]
    #[TestDox('Отправка собирается из реализаций, вес считается по товарам')]
    public function delivery_is_built_from_shipments(): void
    {
        $client = User::factory()->create();
        $first = $this->makeShipment($client, weightKg: 1.5, quantity: 2);
        $second = $this->makeShipment($client, weightKg: 0.25, quantity: 4);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/deliveries', $this->payload([$first->id, $second->id]))
            ->assertRedirect();

        $delivery = DeliveryShipment::query()->firstOrFail();

        // 1.5 кг × 2 + 0.25 кг × 4 = 4 кг = 4000 г
        $this->assertSame(4000, $delivery->calculated_weight);
        // Кладовщик взвесил груз — в ТК уходит его цифра, а не расчётная.
        $this->assertSame(3200, $delivery->declared_weight);
        $this->assertSame(3200, $delivery->effective_weight);
        $this->assertSame(2, $delivery->shipments()->count());
        $this->assertSame('DS-'.str_pad((string) $delivery->id, 6, '0', STR_PAD_LEFT), $delivery->number);
        $this->assertSame('Москва', $delivery->recipient_city);
    }

    #[Test]
    #[TestDox('Товар без веса считается по значению из конфига')]
    public function weightless_products_fall_back_to_config(): void
    {
        $shipment = $this->makeShipment(weightKg: null, quantity: 3);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/deliveries', $this->payload([$shipment->id]))
            ->assertRedirect();

        // 3 штуки × 500 г из APISHIP_DEFAULT_WEIGHT_GRAMS
        $this->assertSame(1500, DeliveryShipment::query()->firstOrFail()->calculated_weight);
    }

    #[Test]
    #[TestDox('Реализацию нельзя включить в две активные отправки')]
    public function shipment_cannot_join_two_active_deliveries(): void
    {
        $shipment = $this->makeShipment();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->post('/wms/deliveries', $this->payload([$shipment->id]));

        $this->actingAs($storekeeper)
            ->post('/wms/deliveries', $this->payload([$shipment->id]))
            ->assertSessionHas('error');

        $this->assertSame(1, DeliveryShipment::query()->count());
    }

    #[Test]
    #[TestDox('Реализации разных клиентов в одну отправку не собираются')]
    public function shipments_of_different_clients_are_rejected(): void
    {
        $first = $this->makeShipment();
        $second = $this->makeShipment();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/deliveries', $this->payload([$first->id, $second->id]))
            ->assertSessionHas('error');

        $this->assertSame(0, DeliveryShipment::query()->count());
    }

    #[Test]
    #[TestDox('Доставка до двери без номера дома не проходит валидацию')]
    public function door_delivery_requires_house(): void
    {
        $shipment = $this->makeShipment();
        $payload = $this->payload([$shipment->id]);
        $payload['recipient']['house'] = '';

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post('/wms/deliveries', $payload)
            ->assertSessionHasErrors('recipient.house');
    }

    #[Test]
    #[TestDox('Расчёт схлопывает ветки ответа в один список тарифов, отсортированный по цене')]
    public function calculator_flattens_and_sorts_tariffs(): void
    {
        $this->fakeApiShip([
            '*/calculator' => Http::response([
                'deliveryToDoor' => [[
                    'providerKey' => 'dpd',
                    'tariffs' => [[
                        'tariffId' => 300,
                        'tariffName' => 'Курьер',
                        'deliveryCost' => 1200,
                        'calendarDaysMin' => 2,
                        'calendarDaysMax' => 4,
                    ]],
                ]],
                'deliveryToPoint' => [[
                    'providerKey' => 'cdek',
                    'tariffs' => [[
                        'tariffId' => 137,
                        'tariffName' => 'Посылка склад-склад',
                        'deliveryCost' => 750,
                        'deliveryCostOriginal' => 900,
                        'calendarDaysMin' => 3,
                        'calendarDaysMax' => 5,
                    ]],
                ]],
            ], 200),
        ]);

        $delivery = DeliveryShipment::factory()->create();
        $this->addPlace($delivery);

        $response = $this->actingAs($this->userWithRole('storekeeper'))
            ->postJson("/wms/deliveries/{$delivery->id}/calculate")
            ->assertOk();

        $tariffs = $response->json('tariffs');

        $this->assertCount(2, $tariffs);
        $this->assertSame('cdek', $tariffs[0]['provider_key']);
        $this->assertSame(2, $tariffs[0]['delivery_type']);
        $this->assertEquals(750, $tariffs[0]['delivery_cost']);
        $this->assertSame('dpd', $tariffs[1]['provider_key']);

        // Вес и габариты уезжают в граммах и сантиметрах — это требование ApiShip.
        $sent = $this->sentPayload('/calculator');
        $this->assertSame(3200, $sent['weight']);
        $this->assertSame(40, $sent['length']);
        $this->assertSame('Москва', $sent['to']['city']);
    }

    #[Test]
    #[TestDox('Заявка уходит перевозчику и сохраняет идентификатор ApiShip')]
    public function submit_creates_order(): void
    {
        $this->fakeApiShip([
            '*/v1/orders' => Http::response(['orderId' => '4561111', 'created' => '2026-08-09T10:00:00+03:00'], 200),
        ]);

        $delivery = DeliveryShipment::factory()->calculated()->create();
        $this->addPlace($delivery);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit")
            ->assertSessionHas('success');

        $delivery->refresh();

        $this->assertSame('4561111', $delivery->apiship_order_id);
        $this->assertSame('submitted', $delivery->status->value);
        $this->assertNotNull($delivery->submitted_at);

        $sent = $this->sentPayload('/v1/orders');
        // clientNumber — ключ идемпотентности: по нему приходят вебхуки.
        $this->assertSame($delivery->number, $sent['order']['clientNumber']);
        $this->assertSame('cdek', $sent['order']['providerKey']);
        $this->assertSame(137, $sent['order']['tariffId']);
        $this->assertSame(3200, $sent['order']['weight']);
        $this->assertSame('Тюмень', $sent['sender']['city']);
    }

    #[Test]
    #[TestDox('Блок cost не требует с получателя ни копейки')]
    public function nothing_is_collected_from_the_recipient(): void
    {
        $this->fakeApiShip([
            '*/v1/orders' => Http::response(['orderId' => '4561113', 'created' => '2026-08-12T05:00:00+03:00'], 200),
        ]);

        $delivery = DeliveryShipment::factory()->calculated()->create(['delivery_cost' => 361.12]);
        $this->addPlace($delivery);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit");

        $sent = $this->sentPayload('/v1/orders');

        // deliveryCost — это сумма, которую перевозчик возьмёт С ПОЛУЧАТЕЛЯ, а не наш
        // тариф. С тарифом в этом поле DPD отклоняет заявку целиком.
        $this->assertSame(0, $sent['cost']['deliveryCost']);
        $this->assertSame(0, $sent['cost']['codCost']);
        // Наша стоимость при этом никуда не девается — она нужна для сводки расходов.
        $this->assertEquals(361.12, $delivery->fresh()->delivery_cost);
    }

    #[Test]
    #[TestDox('Сумма к получению по позициям сходится с наложенным платежом заявки')]
    public function item_cod_matches_order_cod(): void
    {
        $this->fakeApiShip([
            '*/v1/orders' => Http::response(['orderId' => '4561112', 'created' => '2026-08-12T03:00:00+03:00'], 200),
        ]);

        $shipment = $this->makeShipment();
        $delivery = DeliveryShipment::factory()->calculated()->create(['user_id' => $shipment->user_id]);
        $delivery->shipments()->attach($shipment->id);
        $this->addPlace($delivery);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit");

        $sent = $this->sentPayload('/v1/orders');
        $items = $sent['places'][0]['items'];

        $this->assertNotEmpty($items);

        // `cost` у позиции — наложенный платёж, а не цена. Перевозчик сверяет его
        // сумму с codCost заявки, и расхождение отклоняет заявку целиком.
        $this->assertSame(0.0, array_sum(array_column($items, 'cost')));
        $this->assertSame((float) $sent['cost']['codCost'], array_sum(array_column($items, 'cost')));
        // Объявленная ценность при этом сохраняется — она нужна для страховки.
        $this->assertGreaterThan(0, array_sum(array_column($items, 'assessedCost')));
    }

    #[Test]
    #[TestDox('Ошибка валидации от перевозчика переводит отправку в failed с текстом причины')]
    public function submit_failure_is_recorded(): void
    {
        $this->fakeApiShip([
            '*/v1/orders' => Http::response([
                'code' => 400,
                'message' => 'Ошибка валидации',
                'errors' => [
                    ['field' => 'recipient.index', 'message' => 'Не заполнен индекс получателя'],
                ],
            ], 400),
        ]);

        $delivery = DeliveryShipment::factory()->calculated()->create();
        $this->addPlace($delivery, length: null, width: null, height: null);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit")
            ->assertSessionHas('error');

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status->value);
        $this->assertStringContainsString('Не заполнен индекс получателя', $delivery->last_error);
        $this->assertNull($delivery->apiship_order_id);

        // Неудачный вызов записан в журнал — иначе разбирать отказ будет нечем.
        $this->assertDatabaseHas('apiship_requests', [
            'delivery_shipment_id' => $delivery->id,
            'operation' => 'create_order',
            'http_status' => 400,
        ]);
    }

    #[Test]
    #[TestDox('Не-JSON от перевозчика не роняет передачу заявки')]
    public function non_json_response_is_handled(): void
    {
        $this->fakeApiShip([
            '*/v1/orders' => Http::response('<html>502 Bad Gateway</html>', 502),
        ]);

        $delivery = DeliveryShipment::factory()->calculated()->create();
        $this->addPlace($delivery, length: null, width: null, height: null);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit")
            ->assertSessionHas('error');

        $this->assertSame('failed', $delivery->fresh()->status->value);
        $this->assertDatabaseHas('apiship_requests', ['operation' => 'create_order', 'http_status' => 502]);
    }

    #[Test]
    #[TestDox('При выключенной интеграции наружу не уходит ни одного запроса')]
    public function nothing_is_sent_when_integration_disabled(): void
    {
        config()->set('services.apiship.enabled', false);
        Http::fake();

        $delivery = DeliveryShipment::factory()->calculated()->create();
        $this->addPlace($delivery, length: null, width: null, height: null);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit")
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame('failed', $delivery->fresh()->status->value);
    }

    #[Test]
    #[TestDox('Повторная передача уже созданной заявки не дублирует её у перевозчика')]
    public function submit_is_not_repeated(): void
    {
        $this->fakeApiShip();

        $delivery = DeliveryShipment::factory()->submitted()->create();
        $this->addPlace($delivery, length: null, width: null, height: null);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/submit")
            ->assertSessionHas('error');

        $this->assertNull($this->sentPayload('/v1/orders'));
    }

    #[Test]
    #[TestDox('Выбор тарифа сохраняется и переводит черновик в «Тариф выбран»')]
    public function choosing_tariff_updates_draft(): void
    {
        $shipment = $this->makeShipment();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->post('/wms/deliveries', $this->payload([$shipment->id]));
        $delivery = DeliveryShipment::query()->firstOrFail();

        $this->actingAs($storekeeper)
            ->put("/wms/deliveries/{$delivery->id}", $this->payload([$shipment->id], [
                'provider_key' => 'cdek',
                'tariff_id' => 137,
                'tariff_name' => 'Посылка склад-склад',
                'delivery_cost' => 750,
            ]))
            ->assertSessionHas('success');

        $delivery->refresh();

        $this->assertSame('calculated', $delivery->status->value);
        $this->assertSame('cdek', $delivery->provider_key);
        $this->assertSame(137, $delivery->tariff_id);
        $this->assertEquals(750, $delivery->delivery_cost);
        // Состав не размножился: sync, а не attach.
        $this->assertSame(1, $delivery->shipments()->count());
    }

    #[Test]
    #[TestDox('Доставка до ПВЗ без выбранного пункта не сохраняется')]
    public function point_delivery_requires_point_id(): void
    {
        $shipment = $this->makeShipment();
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->post('/wms/deliveries', $this->payload([$shipment->id]));
        $delivery = DeliveryShipment::query()->firstOrFail();

        $this->actingAs($storekeeper)
            ->put("/wms/deliveries/{$delivery->id}", $this->payload([$shipment->id], [
                'delivery_type' => 2,
                'provider_key' => 'cdek',
                'tariff_id' => 137,
            ]))
            ->assertSessionHasErrors('point_id');
    }

    #[Test]
    #[TestDox('Переданную заявку нельзя редактировать')]
    public function submitted_delivery_is_not_editable(): void
    {
        $shipment = $this->makeShipment();
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->put("/wms/deliveries/{$delivery->id}", $this->payload([$shipment->id]))
            ->assertSessionHas('error');
    }

    #[Test]
    #[TestDox('Готовый APISHIP_TOKEN используется как есть, без вызова /login')]
    public function configured_token_skips_login(): void
    {
        config()->set('services.apiship.token', 'token-from-cabinet');
        config()->set('services.apiship.login', null);
        config()->set('services.apiship.password', null);

        Http::fake(['*/calculator' => Http::response(['deliveryToDoor' => []], 200)]);

        $delivery = DeliveryShipment::factory()->create();
        $this->addPlace($delivery);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->postJson("/wms/deliveries/{$delivery->id}/calculate")
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'token-from-cabinet'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/login'));
    }

    #[Test]
    #[TestDox('Токен ApiShip берётся один раз и переиспользуется')]
    public function token_is_cached_between_calls(): void
    {
        $this->fakeApiShip([
            '*/calculator' => Http::response(['deliveryToDoor' => [], 'deliveryToPoint' => []], 200),
        ]);

        $delivery = DeliveryShipment::factory()->create();
        $this->addPlace($delivery, length: null, width: null, height: null);
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->postJson("/wms/deliveries/{$delivery->id}/calculate");
        $this->actingAs($storekeeper)->postJson("/wms/deliveries/{$delivery->id}/calculate");

        $logins = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/login'))
            ->count();

        $this->assertSame(1, $logins);
    }
}
