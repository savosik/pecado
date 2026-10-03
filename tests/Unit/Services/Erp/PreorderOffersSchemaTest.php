<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Контракт предзаказа — проект v16.18.0 (топик Agent Hub №18).
 *
 * Три схемы: новое событие `preorder_offers.updated` (1С → Сайт), срок яруса
 * `items[].lead_time_days` в `order.created` сайта и `expected_date` в `order.updated` от 1С.
 *
 * Снимок предложений целиком заменяет прежний, поэтому схема строга к тому, на чём держится
 * перезапись и расчёт доступного: `calculated_at` только со смещением (иначе защита от
 * перестановки сравнивает время в разных поясах), количество и срок — целые. Пустой `offers`
 * допустим: это «предзаказать нечего», а не ошибка.
 *
 * Лишнее поле `price` схему НЕ нарушает намеренно: все схемы обмена допускают незнакомые
 * поля, и запрет превратил бы справочное поле в отказ от всего снимка — товар завис бы
 * со старым предложением. Сайт такое поле просто не читает.
 */
class PreorderOffersSchemaTest extends TestCase
{
    private const EVENT = 'preorder_offers.updated';

    private const PREORDER_WAREHOUSE = '38dcd8b2-be0a-4861-974f-44c2a71e7789';

    private const PRODUCT = '550e8400-e29b-41d4-a716-446655440001';

    private ErpMessageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ErpMessageValidator;
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return [
            'event' => self::EVENT,
            'message_id' => 'msg-preorder-offers-0001',
            'product_uuid' => self::PRODUCT,
            'warehouse_uuid' => self::PREORDER_WAREHOUSE,
            'calculated_at' => '2026-10-02T15:00:00.123+03:00',
            'offers' => [
                ['quantity' => 20, 'lead_time_days' => 5],
                ['quantity' => 100, 'lead_time_days' => 14],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertValid(array $payload, string $event = self::EVENT): void
    {
        $result = $this->validator->validate($event, $payload);

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    #[Test]
    public function schema_is_registered(): void
    {
        $this->assertTrue($this->validator->hasSchema(self::EVENT));
    }

    #[Test]
    public function snapshot_with_two_tiers_is_valid(): void
    {
        $this->assertValid(self::base());
    }

    #[Test]
    public function empty_offers_means_nothing_to_preorder_and_is_valid(): void
    {
        $this->assertValid(array_merge(self::base(), ['offers' => []]));
    }

    #[Test]
    public function single_tier_is_valid(): void
    {
        $this->assertValid(array_merge(self::base(), ['offers' => [['quantity' => 1, 'lead_time_days' => 7]]]));
    }

    /**
     * Уточнение seq 10 (03.10.2026): источник без срока 1С в предложения не включает,
     * «через 0 дней» клиент видеть не должен — минимум срока 1.
     */
    #[Test]
    public function lead_time_of_one_day_is_valid(): void
    {
        $this->assertValid(array_merge(self::base(), ['offers' => [['quantity' => 3, 'lead_time_days' => 1]]]));
    }

    #[Test]
    public function calculated_at_without_milliseconds_and_in_utc_is_valid(): void
    {
        $this->assertValid(array_merge(self::base(), ['calculated_at' => '2026-10-02T15:00:00+03:00']));
        $this->assertValid(array_merge(self::base(), ['calculated_at' => '2026-10-02T12:00:00.123Z']));
    }

    #[Test]
    public function extra_price_field_is_tolerated_but_not_part_of_contract(): void
    {
        $payload = self::base();
        $payload['offers'][0]['price'] = 1000.00;
        $payload['price'] = 1000.00;

        $this->assertValid($payload);
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        $unset = fn (string $key) => function (array $p) use ($key) {
            unset($p[$key]);

            return $p;
        };
        $offer = fn (string $field, mixed $value) => function (array $p) use ($field, $value) {
            $p['offers'][0][$field] = $value;

            return $p;
        };
        $offerWithout = fn (string $field) => function (array $p) use ($field) {
            unset($p['offers'][0][$field]);

            return $p;
        };

        return [
            'calculated_at без смещения' => [fn (array $p) => array_merge($p, ['calculated_at' => '2026-10-02T15:00:00'])],
            'calculated_at без смещения, с миллисекундами' => [fn (array $p) => array_merge($p, ['calculated_at' => '2026-10-02T15:00:00.123'])],
            'calculated_at — только дата' => [fn (array $p) => array_merge($p, ['calculated_at' => '2026-10-02'])],
            'нет calculated_at' => [$unset('calculated_at')],
            'нет offers' => [$unset('offers')],
            'offers = null' => [fn (array $p) => array_merge($p, ['offers' => null])],
            'нет warehouse_uuid' => [$unset('warehouse_uuid')],
            'нет product_uuid' => [$unset('product_uuid')],
            'нет message_id' => [$unset('message_id')],
            'чужой event' => [fn (array $p) => array_merge($p, ['event' => 'stock.updated'])],
            'product_uuid не GUID' => [fn (array $p) => array_merge($p, ['product_uuid' => '9015108'])],
            'warehouse_uuid не GUID' => [fn (array $p) => array_merge($p, ['warehouse_uuid' => 'Москва Предзаказ'])],
            'quantity = 0' => [$offer('quantity', 0)],
            'отрицательное quantity' => [$offer('quantity', -3)],
            'дробное quantity' => [$offer('quantity', 2.5)],
            'quantity строкой' => [$offer('quantity', '20')],
            'дробный срок' => [$offer('lead_time_days', 2.5)],
            'нулевой срок' => [$offer('lead_time_days', 0)],
            'отрицательный срок' => [$offer('lead_time_days', -1)],
            'срок строкой' => [$offer('lead_time_days', '5')],
            'срок = null' => [$offer('lead_time_days', null)],
            'ярус без срока' => [$offerWithout('lead_time_days')],
            'ярус без количества' => [$offerWithout('quantity')],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_snapshot_is_rejected(callable $mutate): void
    {
        $result = $this->validator->validate(self::EVENT, $mutate(self::base()));

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    // ── order.created (Сайт → 1С): срок яруса в строке ─────────────────────────

    /**
     * Предзаказ сверх ближайшего яруса: две строки одного товара с одной ценой и разным сроком.
     *
     * @return array<string, mixed>
     */
    private static function preorderCreated(): array
    {
        $line = fn (int $number, int $quantity, int $leadTime) => [
            'line_number' => $number,
            'product_uuid' => self::PRODUCT,
            'quantity' => $quantity,
            'base_price' => 1200,
            'discount_percent' => 10,
            'final_price' => 1080,
            'lead_time_days' => $leadTime,
        ];

        return [
            'event' => 'order.created',
            'message_id' => 'msg-order-created-0001',
            'uuid' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'type' => 'preorder',
            'warehouse_uuids' => [self::PREORDER_WAREHOUSE],
            'reserve' => false,
            'items' => [$line(1, 20, 5), $line(2, 30, 14)],
        ];
    }

    #[Test]
    public function outbound_preorder_with_two_lines_of_one_product_is_valid(): void
    {
        $result = $this->validator->validateOutbound('order.created', self::preorderCreated());

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    #[Test]
    public function outbound_order_line_with_one_day_lead_time_is_valid(): void
    {
        $payload = self::preorderCreated();
        $payload['items'][0]['lead_time_days'] = 1;

        $result = $this->validator->validateOutbound('order.created', $payload);

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    #[Test]
    public function outbound_order_line_without_lead_time_is_valid(): void
    {
        $payload = self::preorderCreated();
        $payload['type'] = 'order';
        unset($payload['items'][0]['lead_time_days'], $payload['items'][1]);

        $result = $this->validator->validateOutbound('order.created', $payload);

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidLeadTimes(): array
    {
        return [
            'дробный' => [2.5],
            'нулевой' => [0],
            'отрицательный' => [-1],
            'строкой' => ['5'],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('invalidLeadTimes')]
    public function outbound_order_line_with_bad_lead_time_is_rejected(mixed $leadTime): void
    {
        $payload = self::preorderCreated();
        $payload['items'][0]['lead_time_days'] = $leadTime;

        $this->assertFalse($this->validator->validateOutbound('order.created', $payload)['valid']);
    }

    // ── order.updated (1С → Сайт): ожидаемая дата и type ───────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function orderUpdated(): array
    {
        return [
            'event' => 'order.updated',
            'message_id' => 'msg-order-updated-0001',
            'uuid' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'type' => 'preorder',
            // Предзаказ 1С проводит на «Москва Основной», тип остаётся preorder.
            'warehouse_uuid' => '40301d16-3847-11e1-8034-001e6711ed1d',
            'expected_date' => '2026-10-20',
        ];
    }

    #[Test]
    public function order_updated_with_expected_date_is_valid(): void
    {
        $this->assertValid(self::orderUpdated(), 'order.updated');
    }

    #[Test]
    public function order_updated_without_expected_date_or_with_null_is_valid(): void
    {
        $withNull = array_merge(self::orderUpdated(), ['expected_date' => null]);
        $without = self::orderUpdated();
        unset($without['expected_date']);

        $this->assertValid($withNull, 'order.updated');
        $this->assertValid($without, 'order.updated');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidExpectedDates(): array
    {
        return [
            'дата со временем' => ['2026-10-20T00:00:00+03:00'],
            'русский формат' => ['20.10.2026'],
            'несуществующая дата' => ['2026-13-45'],
            'число' => [20261020],
        ];
    }

    #[Test]
    #[DataProvider('invalidExpectedDates')]
    public function order_updated_with_bad_expected_date_is_rejected(mixed $date): void
    {
        $payload = array_merge(self::orderUpdated(), ['expected_date' => $date]);

        $this->assertFalse($this->validator->validate('order.updated', $payload)['valid']);
    }

    /**
     * С v16.18.0 1С присылает `type` всегда, включая `order` у обычного заказа.
     */
    #[Test]
    public function type_order_is_accepted_from_erp_in_created_and_updated(): void
    {
        $updated = array_merge(self::orderUpdated(), ['type' => 'order']);
        unset($updated['expected_date']);

        $created = [
            'event' => 'order.created',
            'message_id' => 'msg-order-created-erp-0001',
            'uuid' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'type' => 'order',
            'partner_uuid' => '9b2f6f0e-1c57-4c1f-8f0a-3c5d7e9a1b2c',
            'contractor' => [
                'uuid' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
                'tax_id' => '7701234567',
            ],
            'items' => [[
                'line_number' => 1,
                'product_uuid' => self::PRODUCT,
                'quantity' => 2,
                'base_price' => 1200,
                'discount_percent' => 10,
                'final_price' => 1080,
            ]],
        ];

        $this->assertValid($updated, 'order.updated');
        $this->assertValid($created, 'order.created');
    }
}
