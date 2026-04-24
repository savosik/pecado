<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleShipmentCreatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_shipment_with_items(): void
    {
        $product = Product::factory()->create(['external_id' => 'ship-prod-001']);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0001',
            'tax_id' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'currency_code' => 'KZT',
            'items' => [
                [
                    'product_uuid' => 'ship-prod-001',
                    'quantity' => 10,
                    'price' => 3000.00,
                ],
            ],
        ]);

        $this->assertDatabaseHas('shipments', [
            'uuid' => 's1a2b3c4-test-0001',
            'tax_id' => '1234567890',
            'status' => 'completed',
            'currency_code' => 'KZT',
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0001')->first();
        $this->assertNotNull($shipment);
        $this->assertCount(1, $shipment->items);
        $this->assertEquals(30000.00, (float) $shipment->total_amount);
        $this->assertEquals($product->id, $shipment->items->first()->product_id);
    }

    #[Test]
    public function it_links_to_company_by_contractor_inn_when_partner_uuid_provided(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-uuid-002']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '9876543210',
        ]);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0002',
            'tax_id' => '9876543210',
            'partner_uuid' => 'partner-uuid-002',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0002')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals($company->id, $shipment->company_id);
        $this->assertEquals($user->id, $shipment->user_id);
    }

    #[Test]
    public function it_does_not_link_to_other_users_company_with_same_tax_id_without_partner_uuid(): void
    {
        // Regression для v13.2 security-fix: без partner_uuid не должен найти чужую Company по ИНН
        $user = User::factory()->create();
        Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '9876543210',
        ]);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0002b',
            'tax_id' => '9876543210',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0002b')->first();
        $this->assertNotNull($shipment);
        $this->assertNull($shipment->company_id, 'Company не должна найтись без partner_uuid');
        $this->assertNull($shipment->user_id);
    }

    #[Test]
    public function it_links_to_company_by_contractor_uuid_as_priority(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-uuid-003']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1111111111',
            'erp_id' => 'contractor-uuid-003',
        ]);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0002c',
            'contractor_uuid' => 'contractor-uuid-003',
            'tax_id' => '1111111111',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0002c')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals($company->id, $shipment->company_id);
        $this->assertEquals($user->id, $shipment->user_id);
    }

    #[Test]
    public function it_backfills_company_erp_id_when_found_by_inn_with_uuid_in_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-uuid-004']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '2222222222',
            'erp_id' => null,
        ]);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0002d',
            'contractor_uuid' => 'contractor-uuid-004',
            'tax_id' => '2222222222',
            'partner_uuid' => 'partner-uuid-004',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
        ]);

        $company->refresh();
        $this->assertEquals('contractor-uuid-004', $company->erp_id, 'Company.erp_id должен быть заполнен lazy backfill');
    }

    #[Test]
    public function it_creates_shipment_without_company_when_inn_not_found(): void
    {
        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0003',
            'tax_id' => '0000000000',
            'date' => '2026-02-16',
            'status' => 'new',
            'items' => [],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0003')->first();
        $this->assertNotNull($shipment);
        $this->assertNull($shipment->company_id);
        $this->assertNull($shipment->user_id);
    }

    #[Test]
    public function it_is_idempotent_uses_update_or_create(): void
    {
        $handler = new HandleShipmentCreated;

        // Первое создание
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0004',
            'tax_id' => '1111111111',
            'date' => '2026-02-16',
            'status' => 'new',
            'items' => [],
        ]);

        // Повторный вызов с тем же UUID — обновление
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0004',
            'tax_id' => '1111111111',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
        ]);

        $this->assertDatabaseCount('shipments', 1);
        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0004')->first();
        $this->assertEquals('completed', $shipment->status);
    }

    #[Test]
    public function it_does_nothing_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует uuid');
            });

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'tax_id' => '1234567890',
        ]);

        $this->assertDatabaseCount('shipments', 0);
    }

    #[Test]
    public function it_creates_items_without_product_when_product_not_found(): void
    {
        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0005',
            'tax_id' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [
                [
                    'product_uuid' => 'nonexistent-uuid',
                    'quantity' => 5,
                    'price' => 2000.00,
                ],
            ],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0005')->first();
        $this->assertCount(1, $shipment->items);
        $this->assertNull($shipment->items->first()->product_id);
        $this->assertEquals(10000.00, (float) $shipment->total_amount);
    }

    #[Test]
    public function it_creates_shipment_with_multiple_items(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'ship-multi-001']);
        $product2 = Product::factory()->create(['external_id' => 'ship-multi-002']);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-test-0006',
            'tax_id' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => 'ship-multi-001', 'quantity' => 10, 'price' => 1000.00],
                ['product_uuid' => 'ship-multi-002', 'quantity' => 5, 'price' => 2000.00],
            ],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-test-0006')->first();
        $this->assertCount(2, $shipment->items);
        $this->assertEquals(20000.00, (float) $shipment->total_amount);
    }

    #[Test]
    public function it_accepts_negative_auto_discount_percent_as_markup(): void
    {
        // Регрессионный тест: 1С передаёт отрицательный auto_discount_percent (наценка).
        // Схема shipment.created не должна требовать minimum: 0 (исправлено в v12.7.4).
        $product = Product::factory()->create(['external_id' => 'ship-neg-discount-001']);

        $handler = new HandleShipmentCreated;
        $handler->handle([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-neg-disc-001',
            'tax_id' => '1234567890',
            'date' => '2026-04-16',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                [
                    'product_uuid' => 'ship-neg-discount-001',
                    'quantity' => 5,
                    'price' => 1000.00,
                    'auto_discount_percent' => -10,
                    'manual_discount_percent' => -5,
                    'total' => 5750.00,
                ],
            ],
        ]);

        $shipment = Shipment::where('uuid', 's1a2b3c4-neg-disc-001')->first();
        $this->assertNotNull($shipment);

        $item = $shipment->items->first();
        $this->assertNotNull($item);
        $this->assertEquals(-10.00, (float) $item->auto_discount_percent);
        $this->assertEquals(-5.00, (float) $item->manual_discount_percent);
        $this->assertEquals(5750.00, (float) $shipment->total_amount);
    }
}
