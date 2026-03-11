<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Discount;
use App\Models\PartnerSegment;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\User;
use App\Services\Erp\Handlers\HandleDiscountUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleDiscountUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function updates_discount_fields_and_relations(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'upd-prod-001']);
        $product2 = Product::factory()->create(['external_id' => 'upd-prod-002']);
        $user = User::factory()->create(['erp_id' => 'upd-partner-001']);

        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-upd-0001-0001-000000000001',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);
        $discount->products()->attach($product1);

        $handler = app(HandleDiscountUpdated::class);
        $handler->handle([
            'event' => 'discount.updated',
            'uuid' => 'd1e2f3a4-upd-0001-0001-000000000001',
            'type' => 'promotion',
            'value' => 25.00,
            'starts_at' => '2026-05-01T00:00:00',
            'ends_at' => '2026-12-31T23:59:59',
            'product_uuids' => ['upd-prod-002'],
            'partner_uuids' => ['upd-partner-001'],
        ]);

        $discount->refresh();
        $this->assertEquals('promotion', $discount->type);
        $this->assertEquals(25.00, (float) $discount->percentage);
        $this->assertCount(1, $discount->products);
        $this->assertTrue($discount->products->contains($product2));
        $this->assertFalse($discount->products->contains($product1));
        $this->assertCount(1, $discount->users);
        $this->assertTrue($discount->users->contains($user));
    }

    #[Test]
    public function ignores_event_for_unknown_uuid(): void
    {
        $handler = app(HandleDiscountUpdated::class);
        $handler->handle([
            'event' => 'discount.updated',
            'uuid' => 'nonexistent-discount-uuid',
            'type' => 'agreement',
            'value' => 10.00,
            'product_uuids' => [],
            'partner_uuids' => [],
        ]);

        $this->assertDatabaseCount('discounts', 0);
    }

    #[Test]
    public function missing_uuid_does_nothing(): void
    {
        $handler = app(HandleDiscountUpdated::class);
        $handler->handle([
            'event' => 'discount.updated',
            'type' => 'agreement',
            'value' => 10.00,
        ]);

        $this->assertDatabaseCount('discounts', 0);
    }

    #[Test]
    public function clears_relations_when_empty_uuids(): void
    {
        $product = Product::factory()->create(['external_id' => 'clear-prod-001']);
        $user = User::factory()->create(['erp_id' => 'clear-partner-001']);

        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-upd-0001-0001-000000000002',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);
        $discount->products()->attach($product);
        $discount->users()->attach($user);

        $handler = app(HandleDiscountUpdated::class);
        $handler->handle([
            'event' => 'discount.updated',
            'uuid' => 'd1e2f3a4-upd-0001-0001-000000000002',
            'type' => 'agreement',
            'value' => 10.00,
            'product_uuids' => [],
            'partner_uuids' => [],
        ]);

        $discount->refresh();
        $this->assertCount(0, $discount->products);
        $this->assertCount(0, $discount->users);
    }

    #[Test]
    public function syncs_product_and_partner_segments(): void
    {
        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-upd-0001-0001-000000000003',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);

        $productSegment = ProductSegment::create(['uuid' => 'seg-prod-upd-001', 'name' => 'Масла']);
        $partnerSegment = PartnerSegment::create(['uuid' => 'seg-part-upd-001', 'name' => 'Уровень Серебро']);

        $handler = app(HandleDiscountUpdated::class);
        $handler->handle([
            'event' => 'discount.updated',
            'uuid' => 'd1e2f3a4-upd-0001-0001-000000000003',
            'type' => 'promotion',
            'value' => 20.00,
            'product_uuids' => [],
            'partner_uuids' => [],
            'product_segment_uuids' => ['seg-prod-upd-001'],
            'partner_segment_uuids' => ['seg-part-upd-001'],
        ]);

        $discount->refresh();
        $this->assertCount(1, $discount->productSegments);
        $this->assertCount(1, $discount->partnerSegments);
        $this->assertTrue($discount->productSegments->contains($productSegment));
        $this->assertTrue($discount->partnerSegments->contains($partnerSegment));
    }
}
