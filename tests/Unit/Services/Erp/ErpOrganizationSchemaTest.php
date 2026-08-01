<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Опциональные поля организации во входящих схемах (v15.8.0, карточка org-02).
 *
 * Смысл этих тестов — гарантия обратной совместимости в обе стороны: 1С может начать
 * присылать организацию в любой момент до того, как сайт научится её читать, и наоборот
 * может не присылать её ещё долго. Ни один из вариантов не должен ронять валидацию.
 */
class ErpOrganizationSchemaTest extends TestCase
{
    private ErpMessageValidator $validator;

    private const ORG_UUID = '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce';

    private const WAREHOUSE_UUID = 'f8083799-0838-11e0-a1ea-505054503030';

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ErpMessageValidator;
    }

    /**
     * Минимальные валидные payload-ы каждого затронутого события — без организации.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function payloads(): array
    {
        return [
            'order.created' => [
                'event' => 'order.created',
                'message_id' => 'msg-org-order-created',
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'partner_uuid' => '550e8400-e29b-41d4-a716-446655440002',
                'contractor' => ['uuid' => '550e8400-e29b-41d4-a716-446655440003', 'tax_id' => '7710140679'],
                'status' => 'pending_approval',
                'items' => [['product_uuid' => '550e8400-e29b-41d4-a716-446655440004', 'quantity' => 1, 'final_price' => 100]],
            ],
            'order.updated' => [
                'event' => 'order.updated',
                'message_id' => 'msg-org-order-updated',
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'status' => 'ready_for_shipment',
            ],
            'shipment.created' => [
                'event' => 'shipment.created',
                'message_id' => 'msg-org-shipment-created',
                'uuid' => '550e8400-e29b-41d4-a716-446655440005',
                'contractor_uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'tax_id' => '7710140679',
                'number' => '29УТ-003413',
                'date' => '2026-08-01',
                'status' => 'completed',
                'currency_code' => 'RUB',
                'items' => [['product_uuid' => '550e8400-e29b-41d4-a716-446655440004', 'quantity' => 1, 'price' => 100]],
            ],
            'shipment.updated' => [
                'event' => 'shipment.updated',
                'message_id' => 'msg-org-shipment-updated',
                'uuid' => '550e8400-e29b-41d4-a716-446655440005',
                'contractor_uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'tax_id' => '7710140679',
                'number' => '29УТ-003413',
                'date' => '2026-08-01',
                'status' => 'completed',
                'currency_code' => 'RUB',
                'items' => [['product_uuid' => '550e8400-e29b-41d4-a716-446655440004', 'quantity' => 1, 'price' => 100]],
            ],
            'return.updated' => [
                'event' => 'return.updated',
                'message_id' => 'msg-org-return-updated',
                'uuid' => '550e8400-e29b-41d4-a716-446655440006',
                'status' => 'for_return',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function documentEvents(): array
    {
        return [
            'order.created' => ['order.created'],
            'order.updated' => ['order.updated'],
            'shipment.created' => ['shipment.created'],
            'shipment.updated' => ['shipment.updated'],
            'return.updated' => ['return.updated'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function eventsWithWarehouse(): array
    {
        return [
            'order.created' => ['order.created'],
            'order.updated' => ['order.updated'],
            'shipment.created' => ['shipment.created'],
            'shipment.updated' => ['shipment.updated'],
        ];
    }

    private function assertValid(string $event, array $payload): void
    {
        $result = $this->validator->validate($event, $payload);

        $this->assertTrue(
            $result['valid'],
            $event.' должен пройти валидацию: '.implode(', ', $result['errors']),
        );
    }

    // ──────────────────────────────────────────────
    // Обратная совместимость: организации может не быть
    // ──────────────────────────────────────────────

    #[Test]
    #[DataProvider('documentEvents')]
    public function payload_without_organization_still_passes(string $event): void
    {
        $this->assertValid($event, self::payloads()[$event]);
    }

    // ──────────────────────────────────────────────
    // Прямая совместимость: организация может прийти раньше, чем сайт научится её читать
    // ──────────────────────────────────────────────

    #[Test]
    #[DataProvider('documentEvents')]
    public function payload_with_full_organization_passes(string $event): void
    {
        $payload = self::payloads()[$event];
        $payload['organization'] = [
            'uuid' => self::ORG_UUID,
            'name' => 'ООО Пекадо',
            'legal_name' => 'Общество с ограниченной ответственностью «Пекадо»',
            'tax_id' => '7710140679',
            'tax_code' => '771001001',
        ];

        $this->assertValid($event, $payload);
    }

    /**
     * Основной ожидаемый случай: справочник ведётся на сайте, 1С шлёт только UUID.
     */
    #[Test]
    #[DataProvider('documentEvents')]
    public function organization_with_uuid_only_passes(string $event): void
    {
        $payload = self::payloads()[$event];
        $payload['organization'] = ['uuid' => self::ORG_UUID];

        $this->assertValid($event, $payload);
    }

    #[Test]
    #[DataProvider('eventsWithWarehouse')]
    public function posted_warehouse_uuid_passes(string $event): void
    {
        $payload = self::payloads()[$event];
        $payload['warehouse_uuid'] = self::WAREHOUSE_UUID;

        $this->assertValid($event, $payload);
    }

    #[Test]
    #[DataProvider('eventsWithWarehouse')]
    public function null_warehouse_uuid_passes(string $event): void
    {
        $payload = self::payloads()[$event];
        $payload['warehouse_uuid'] = null;

        $this->assertValid($event, $payload);
    }

    /**
     * Незнакомые поля не отбраковываются — во всех схемах additionalProperties: true.
     * Без этого любое расширение на стороне 1С клало бы сообщения в ошибки валидации.
     */
    #[Test]
    #[DataProvider('documentEvents')]
    public function unknown_organization_fields_are_not_rejected(string $event): void
    {
        $payload = self::payloads()[$event];
        $payload['organization'] = [
            'uuid' => self::ORG_UUID,
            'okpo' => '12345678',
            'registration_date' => '2010-01-01',
        ];

        $this->assertValid($event, $payload);
    }

    // ──────────────────────────────────────────────
    // Баланс в разрезе организаций
    // ──────────────────────────────────────────────

    private function balancePayload(array $contractorOverride = []): array
    {
        return [
            'event' => 'balance.updated',
            'message_id' => 'msg-org-balance',
            'partner_uuid' => '550e8400-e29b-41d4-a716-446655440002',
            'updated_at' => '2026-08-01T10:00:00+03:00',
            'contractors' => [array_merge([
                'uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'tax_id' => '7710140679',
                'current_balance' => -15000.5,
                'overdue_debt' => 5000,
            ], $contractorOverride)],
        ];
    }

    #[Test]
    public function balance_without_organizations_still_passes(): void
    {
        $this->assertValid('balance.updated', $this->balancePayload());
    }

    #[Test]
    public function balance_with_organizations_passes(): void
    {
        $payload = $this->balancePayload([
            'organizations' => [
                [
                    'uuid' => self::ORG_UUID,
                    'name' => 'ООО Пекадо',
                    'current_balance' => -10000.5,
                    'overdue_debt' => 5000,
                    'overdue_details' => [[
                        'shipment_uuid' => '550e8400-e29b-41d4-a716-446655440005',
                        'amount' => 5000,
                        'due_date' => '2026-07-01',
                    ]],
                ],
                [
                    'uuid' => '9da1768a-40d4-11e1-a692-001e6711ed1d',
                    'current_balance' => -5000,
                    'overdue_debt' => 0,
                ],
            ],
        ]);

        $this->assertValid('balance.updated', $payload);
    }

    #[Test]
    public function balance_with_empty_organizations_array_passes(): void
    {
        $this->assertValid('balance.updated', $this->balancePayload(['organizations' => []]));
    }
}
