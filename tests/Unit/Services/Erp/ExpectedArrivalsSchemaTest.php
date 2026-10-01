<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Схема `product.expected_arrivals.updated` (v16.16.0, проект, топик Agent Hub №16).
 *
 * Снимок по товару целиком заменяет прежний, поэтому схема строга к тому, на чём держится
 * перезапись: `calculated_at` только со смещением (иначе защита от перестановки сравнивает
 * время в разных поясах), количество строго больше нуля, источник из закрытого списка.
 * Пустой `warehouses` допустим — это очистка, а не ошибка.
 */
class ExpectedArrivalsSchemaTest extends TestCase
{
    private const EVENT = 'product.expected_arrivals.updated';

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
            'message_id' => 'msg-arrivals-0001',
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'calculated_at' => '2026-10-01T09:15:00+03:00',
            'warehouses' => [
                [
                    'warehouse_uuid' => '7a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
                    'arrivals' => [
                        ['date' => '2026-10-14', 'quantity' => 20, 'source' => 'purchase'],
                        ['date' => null, 'quantity' => 5, 'source' => 'purchase'],
                    ],
                ],
                [
                    'warehouse_uuid' => '8b2c3d4e-5f6a-4b7c-9d8e-0f1a2b3c4d5e',
                    'arrivals' => [
                        ['date' => '2026-11-02', 'quantity' => 2.5, 'source' => 'import'],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function schema_is_registered(): void
    {
        $this->assertTrue($this->validator->hasSchema(self::EVENT));
    }

    #[Test]
    public function full_snapshot_is_valid(): void
    {
        $result = $this->validator->validate(self::EVENT, self::base());

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    #[Test]
    public function empty_warehouses_clears_and_is_valid(): void
    {
        $payload = array_merge(self::base(), ['warehouses' => []]);

        $result = $this->validator->validate(self::EVENT, $payload);

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    #[Test]
    public function utc_calculated_at_is_valid(): void
    {
        $payload = array_merge(self::base(), ['calculated_at' => '2026-10-01T06:15:00Z']);

        $this->assertTrue($this->validator->validate(self::EVENT, $payload)['valid']);
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'calculated_at без смещения' => [fn (array $p) => array_merge($p, ['calculated_at' => '2026-10-01T09:15:00'])],
            'calculated_at — только дата' => [fn (array $p) => array_merge($p, ['calculated_at' => '2026-10-01'])],
            'нет calculated_at' => [function (array $p) {
                unset($p['calculated_at']);

                return $p;
            }],
            'нет warehouses' => [function (array $p) {
                unset($p['warehouses']);

                return $p;
            }],
            'нет message_id' => [function (array $p) {
                unset($p['message_id']);

                return $p;
            }],
            'чужой event' => [fn (array $p) => array_merge($p, ['event' => 'stock.updated'])],
            'quantity = 0' => [function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['quantity'] = 0;

                return $p;
            }],
            'отрицательное quantity' => [function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['quantity'] = -3;

                return $p;
            }],
            'неизвестный source' => [function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['source'] = 'transfer';

                return $p;
            }],
            'дата не в формате yyyy-MM-dd' => [function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['date'] = '14.10.2026';

                return $p;
            }],
            'строка без поля date' => [function (array $p) {
                unset($p['warehouses'][0]['arrivals'][0]['date']);

                return $p;
            }],
            'склад с пустым arrivals' => [function (array $p) {
                $p['warehouses'][0]['arrivals'] = [];

                return $p;
            }],
            'склад без UUID' => [function (array $p) {
                unset($p['warehouses'][0]['warehouse_uuid']);

                return $p;
            }],
            'product_uuid не GUID' => [fn (array $p) => array_merge($p, ['product_uuid' => '9015108'])],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_payload_is_rejected(callable $mutate): void
    {
        $result = $this->validator->validate(self::EVENT, $mutate(self::base()));

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
}
