<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use App\Services\Erp\Handlers\HandleShipmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Связь строк просрочки с реализациями сайта.
 *
 * Баланс (`erp_in.balances`) и реализации (`erp_in.shipments`) идут разными
 * очередями без гарантии порядка, поэтому проверяются обе гонки: документ раньше
 * баланса и баланс раньше документа. `shipment_uuid` при этом остаётся источником
 * правды и сохраняется всегда — даже когда реализации на сайте нет вовсе.
 */
class OverdueDetailShipmentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_UUID = '550e8400-e29b-41d4-a716-446655440002';

    private const SHIPMENT_UUID = '550e8400-e29b-41d4-a716-446655440005';

    private const ORG_UUID = '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce';

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => '550e8400-e29b-41d4-a716-446655440003',
            'tax_id' => '7710140679',
        ]);
    }

    private function handleBalance(array $detailOverride = []): void
    {
        app(HandleBalanceUpdated::class)->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-'.uniqid(),
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => '2026-08-01T10:00:00+03:00',
            'contractors' => [[
                'uuid' => $this->company->erp_id,
                'tax_id' => '7710140679',
                'current_balance' => -15000.50,
                'overdue_debt' => 5000,
                'overdue_details' => [array_merge([
                    'shipment_uuid' => self::SHIPMENT_UUID,
                    'amount' => 5000,
                    'due_date' => '2026-07-15',
                ], $detailOverride)],
            ]],
        ]);
    }

    private function shipmentPayload(array $override = []): array
    {
        return array_merge([
            'event' => 'shipment.created',
            'message_id' => 'msg-shipment-'.uniqid(),
            'uuid' => self::SHIPMENT_UUID,
            'contractor_uuid' => $this->company->erp_id,
            'partner_uuid' => self::PARTNER_UUID,
            'tax_id' => '7710140679',
            'number' => '29УТ-003413',
            'date' => '2026-07-01',
            'status' => 'completed',
            'currency_code' => 'RUB',
        ], $override);
    }

    private function createShipment(array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => self::SHIPMENT_UUID,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'number' => '29УТ-003413',
            'date' => '2026-07-01',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 5000,
        ], $attributes));
    }

    private function detail(): ContractorBalanceOverdueDetail
    {
        return ContractorBalanceOverdueDetail::query()
            ->where('shipment_uuid', self::SHIPMENT_UUID)
            ->firstOrFail();
    }

    // ──────────────────────────────────────────────
    // Гонка 1: реализация пришла раньше баланса
    // ──────────────────────────────────────────────

    #[Test]
    public function overdue_detail_links_to_existing_shipment(): void
    {
        $shipment = $this->createShipment();

        $this->handleBalance();

        $this->assertSame($shipment->id, $this->detail()->shipment_id);
    }

    #[Test]
    public function linked_shipment_is_reachable_through_relation(): void
    {
        $this->createShipment();

        $this->handleBalance();

        $this->assertSame('29УТ-003413', $this->detail()->shipment->number);
    }

    // ──────────────────────────────────────────────
    // Гонка 2: баланс пришёл раньше реализации
    // ──────────────────────────────────────────────

    #[Test]
    public function overdue_detail_survives_without_shipment(): void
    {
        $this->handleBalance();

        $detail = $this->detail();

        $this->assertNull($detail->shipment_id);
        $this->assertSame(self::SHIPMENT_UUID, $detail->shipment_uuid);
        $this->assertEquals(5000, $detail->amount);
    }

    #[Test]
    public function shipment_created_links_orphaned_overdue_detail(): void
    {
        $this->handleBalance();
        $this->assertNull($this->detail()->shipment_id);

        app(HandleShipmentCreated::class)->handle($this->shipmentPayload());

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();
        $this->assertSame($shipment->id, $this->detail()->shipment_id);
    }

    #[Test]
    public function shipment_updated_links_orphaned_overdue_detail(): void
    {
        $this->handleBalance();

        // Реализация уже существует, но связи нет: строка просрочки могла быть
        // записана до появления FK.
        $shipment = $this->createShipment();
        ContractorBalanceOverdueDetail::query()->update(['shipment_id' => null]);

        app(HandleShipmentUpdated::class)->handle($this->shipmentPayload([
            'event' => 'shipment.updated',
            'status' => 'shipped',
        ]));

        $this->assertSame($shipment->id, $this->detail()->shipment_id);
    }

    #[Test]
    public function unknown_shipment_uuid_leaves_detail_unlinked(): void
    {
        $this->createShipment();

        $this->handleBalance(['shipment_uuid' => '11111111-2222-3333-4444-555555555555']);

        $detail = ContractorBalanceOverdueDetail::query()
            ->where('shipment_uuid', '11111111-2222-3333-4444-555555555555')
            ->firstOrFail();

        $this->assertNull($detail->shipment_id);
        $this->assertEquals(5000, ContractorBalance::query()->firstOrFail()->overdue_debt);
    }

    // ──────────────────────────────────────────────
    // Организация строки просрочки (org-04)
    // ──────────────────────────────────────────────

    #[Test]
    public function link_fills_empty_organization_from_shipment(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);

        $this->handleBalance();
        $this->assertNull($this->detail()->organization_id);

        app(HandleShipmentCreated::class)->handle($this->shipmentPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        $this->assertSame($organization->id, $this->detail()->organization_id);
    }

    #[Test]
    public function link_does_not_override_organization_sent_by_erp(): void
    {
        $fromPayload = Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $fromShipment = Organization::factory()->create(['external_id' => '9da1768a-40d4-11e1-a692-001e6711ed1d']);

        $this->handleBalance(['organization_uuid' => self::ORG_UUID]);
        $this->assertSame($fromPayload->id, $this->detail()->organization_id);

        app(HandleShipmentCreated::class)->handle($this->shipmentPayload([
            'organization' => ['uuid' => $fromShipment->external_id],
        ]));

        $this->assertSame($fromPayload->id, $this->detail()->organization_id);
    }

    // ──────────────────────────────────────────────
    // Повторные сообщения
    // ──────────────────────────────────────────────

    #[Test]
    public function repeated_balance_message_keeps_link(): void
    {
        $shipment = $this->createShipment();

        $this->handleBalance();
        $this->handleBalance();

        $this->assertSame(1, ContractorBalanceOverdueDetail::count());
        $this->assertSame($shipment->id, $this->detail()->shipment_id);
    }
}
