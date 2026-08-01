<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use App\Services\Erp\Handlers\HandleShipmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Организация и склад реализации (v15.8.0, карточка org-04).
 *
 * Реализация — источник организации для возвратов (org-08) и разрезов аналитики
 * (org-09), поэтому её привязка важнее заказной.
 */
class ShipmentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const SHIPMENT_UUID = '550e8400-e29b-41d4-a716-446655440005';

    private const ORG_UUID = '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce';

    private const WAREHOUSE_UUID = 'f8083799-0838-11e0-a1ea-505054503030';

    private User $user;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => '550e8400-e29b-41d4-a716-446655440002']);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => '550e8400-e29b-41d4-a716-446655440003',
            'tax_id' => '7710140679',
        ]);
        $this->product = Product::factory()->create(['external_id' => '550e8400-e29b-41d4-a716-446655440004']);
    }

    private function createdPayload(array $override = []): array
    {
        return array_merge([
            'event' => 'shipment.created',
            'message_id' => 'msg-shipment-org',
            'uuid' => self::SHIPMENT_UUID,
            'contractor_uuid' => $this->company->erp_id,
            'partner_uuid' => $this->user->erp_id,
            'tax_id' => '7710140679',
            'number' => '29УТ-003413',
            'date' => '2026-08-01',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [[
                'product_uuid' => $this->product->external_id,
                'quantity' => 2,
                'price' => 100,
                'total' => 200,
            ]],
        ], $override);
    }

    private function handleCreated(array $payload): void
    {
        app(HandleShipmentCreated::class)->handle($payload);
    }

    private function handleUpdated(array $payload): void
    {
        app(HandleShipmentUpdated::class)->handle(array_merge([
            'event' => 'shipment.updated',
            'message_id' => 'msg-shipment-org-upd',
            'uuid' => self::SHIPMENT_UUID,
        ], $payload));
    }

    #[Test]
    public function shipment_created_with_organization_and_warehouse_links_both(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $warehouse = Warehouse::factory()->create(['external_id' => self::WAREHOUSE_UUID]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
            'warehouse_uuid' => self::WAREHOUSE_UUID,
        ]));

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();

        $this->assertSame($organization->id, $shipment->organization_id);
        $this->assertSame($warehouse->id, $shipment->warehouse_id);
    }

    #[Test]
    public function shipment_created_without_organization_is_still_created(): void
    {
        $this->handleCreated($this->createdPayload());

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();

        $this->assertNull($shipment->organization_id);
        $this->assertNull($shipment->warehouse_id);
        $this->assertSame('29УТ-003413', $shipment->number);
    }

    #[Test]
    public function unknown_organization_uuid_creates_stub(): void
    {
        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID, 'name' => 'Реклама'],
        ]));

        $organization = Organization::where('external_id', self::ORG_UUID)->firstOrFail();

        $this->assertTrue($organization->is_stub);
        $this->assertSame('Реклама', $organization->name);
        $this->assertSame(
            $organization->id,
            Shipment::where('uuid', self::SHIPMENT_UUID)->first()->organization_id,
        );
    }

    #[Test]
    public function shipment_updated_without_organization_does_not_reset_it(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        $this->handleUpdated(['status' => 'completed', 'number' => '29УТ-003414']);

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->first();

        $this->assertSame($organization->id, $shipment->organization_id);
        $this->assertSame('29УТ-003414', $shipment->number);
    }

    #[Test]
    public function shipment_updated_can_change_organization(): void
    {
        Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $second = Organization::factory()->create(['external_id' => '9da1768a-40d4-11e1-a692-001e6711ed1d']);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        $this->handleUpdated(['organization' => ['uuid' => $second->external_id]]);

        $this->assertSame($second->id, Shipment::where('uuid', self::SHIPMENT_UUID)->first()->organization_id);
    }

    /**
     * 1С могла переоформить документ: организация реализации не обязана совпадать
     * с организацией её заказа. Обработку это ронять не должно.
     */
    #[Test]
    public function organization_may_differ_from_order_organization(): void
    {
        $orderOrganization = Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $shipmentOrganization = Organization::factory()->create([
            'external_id' => '9da1768a-40d4-11e1-a692-001e6711ed1d',
        ]);

        app(\App\Services\Erp\Handlers\HandleOrderCreated::class)->handle([
            'event' => 'order.created',
            'message_id' => 'msg-order-for-shipment',
            'uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'partner_uuid' => $this->user->erp_id,
            'contractor' => ['uuid' => $this->company->erp_id, 'tax_id' => '7710140679', 'name' => 'ООО Клиент'],
            'status' => 'pending_approval',
            'organization' => ['uuid' => $orderOrganization->external_id],
            'items' => [[
                'product_uuid' => $this->product->external_id,
                'quantity' => 2,
                'final_price' => 100,
            ]],
        ]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => $shipmentOrganization->external_id],
            'items' => [[
                'product_uuid' => $this->product->external_id,
                'order_uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'quantity' => 2,
                'price' => 100,
                'total' => 200,
            ]],
        ]));

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();

        $this->assertSame($shipmentOrganization->id, $shipment->organization_id);
        $this->assertSame(
            $orderOrganization->id,
            \App\Models\Order::where('uuid', '550e8400-e29b-41d4-a716-446655440001')->first()->organization_id,
        );
    }

    /**
     * shipment.deleted делает soft delete — организация должна пережить удаление
     * и вернуться при восстановлении.
     */
    #[Test]
    public function organization_survives_soft_delete(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        Shipment::where('uuid', self::SHIPMENT_UUID)->first()->delete();

        $shipment = Shipment::withTrashed()->where('uuid', self::SHIPMENT_UUID)->first();

        $this->assertTrue($shipment->trashed());
        $this->assertSame($organization->id, $shipment->organization_id);
    }
}
