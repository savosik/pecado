<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Discount;
use App\Models\PartnerSegment;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\User;
use App\Services\Erp\Handlers\HandleDiscountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleDiscountCreatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_discount_with_products_and_users(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'prod-uuid-001']);
        $product2 = Product::factory()->create(['external_id' => 'prod-uuid-002']);
        $user1 = User::factory()->create(['erp_id' => 'partner-uuid-001']);
        $user2 = User::factory()->create(['erp_id' => 'partner-uuid-002']);

        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000001',
            'type' => 'agreement',
            'value' => 10.00,
            'starts_at' => '2026-01-01T00:00:00',
            'ends_at' => '2026-03-31T23:59:59',
            'product_uuids' => ['prod-uuid-001', 'prod-uuid-002'],
            'partner_uuids' => ['partner-uuid-001', 'partner-uuid-002'],
        ]);

        $this->assertDatabaseHas('discounts', [
            'external_id' => 'd1e2f3a4-0001-0001-0001-000000000001',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);

        $discount = Discount::where('external_id', 'd1e2f3a4-0001-0001-0001-000000000001')->first();
        $this->assertCount(2, $discount->products);
        $this->assertCount(2, $discount->users);
        $this->assertTrue($discount->products->contains($product1));
        $this->assertTrue($discount->products->contains($product2));
        $this->assertTrue($discount->users->contains($user1));
        $this->assertTrue($discount->users->contains($user2));
    }

    #[Test]
    public function idempotent_updates_existing_discount_by_uuid(): void
    {
        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-0001-0001-0001-000000000002',
            'type' => 'agreement',
            'percentage' => 5.00,
            'is_posted' => true,
        ]);

        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000002',
            'type' => 'promotion',
            'value' => 15.00,
            'starts_at' => '2026-02-01T00:00:00',
            'ends_at' => '2026-04-30T23:59:59',
            'product_uuids' => [],
            'partner_uuids' => [],
        ]);

        $discount->refresh();
        $this->assertEquals('promotion', $discount->type);
        $this->assertEquals(15.00, (float) $discount->percentage);
        $this->assertEquals(1, Discount::where('external_id', 'd1e2f3a4-0001-0001-0001-000000000002')->count());
    }

    #[Test]
    public function ignores_unknown_product_and_partner_uuids(): void
    {
        $product = Product::factory()->create(['external_id' => 'known-prod-uuid']);

        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000003',
            'type' => 'agreement',
            'value' => 20.00,
            'product_uuids' => ['known-prod-uuid', 'unknown-prod-uuid'],
            'partner_uuids' => ['unknown-partner-uuid'],
        ]);

        $discount = Discount::where('external_id', 'd1e2f3a4-0001-0001-0001-000000000003')->first();
        $this->assertNotNull($discount);
        $this->assertCount(1, $discount->products);
        $this->assertCount(0, $discount->users);
    }

    #[Test]
    public function missing_uuid_does_not_create_discount(): void
    {
        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'type' => 'agreement',
            'value' => 10.00,
        ]);

        $this->assertDatabaseCount('discounts', 0);
    }

    #[Test]
    public function missing_value_does_not_create_discount(): void
    {
        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000005',
            'type' => 'agreement',
        ]);

        $this->assertDatabaseCount('discounts', 0);
    }

    #[Test]
    public function restores_soft_deleted_discount(): void
    {
        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-0001-0001-0001-000000000006',
            'type' => 'agreement',
            'percentage' => 5.00,
            'is_posted' => false,
        ]);
        $discount->delete(); // soft delete

        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000006',
            'type' => 'promotion',
            'value' => 25.00,
            'product_uuids' => [],
            'partner_uuids' => [],
        ]);

        $discount->refresh();
        $this->assertNull($discount->deleted_at);
        $this->assertTrue($discount->is_posted);
        $this->assertEquals(25.00, (float) $discount->percentage);
    }

    #[Test]
    public function creates_discount_with_product_and_partner_segments(): void
    {
        $productSegment = ProductSegment::create(['uuid' => 'seg-prod-001', 'name' => 'Лубриканты']);
        $partnerSegment = PartnerSegment::create(['uuid' => 'seg-part-001', 'name' => 'Уровень Голд']);

        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000007',
            'type' => 'agreement',
            'value' => 15.00,
            'product_uuids' => [],
            'partner_uuids' => [],
            'product_segment_uuids' => ['seg-prod-001'],
            'partner_segment_uuids' => ['seg-part-001'],
        ]);

        $discount = Discount::where('external_id', 'd1e2f3a4-0001-0001-0001-000000000007')->first();
        $this->assertNotNull($discount);
        $this->assertCount(1, $discount->productSegments);
        $this->assertCount(1, $discount->partnerSegments);
        $this->assertTrue($discount->productSegments->contains($productSegment));
        $this->assertTrue($discount->partnerSegments->contains($partnerSegment));
    }

    #[Test]
    public function ignores_unknown_segment_uuids(): void
    {
        $handler = app(HandleDiscountCreated::class);
        $handler->handle([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-0001-0001-0001-000000000008',
            'type' => 'agreement',
            'value' => 10.00,
            'product_uuids' => [],
            'partner_uuids' => [],
            'product_segment_uuids' => ['unknown-segment-uuid'],
            'partner_segment_uuids' => ['another-unknown-uuid'],
        ]);

        $discount = Discount::where('external_id', 'd1e2f3a4-0001-0001-0001-000000000008')->first();
        $this->assertNotNull($discount);
        $this->assertCount(0, $discount->productSegments);
        $this->assertCount(0, $discount->partnerSegments);
    }
}
