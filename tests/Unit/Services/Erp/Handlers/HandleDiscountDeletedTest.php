<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Discount;
use App\Services\Erp\Handlers\HandleDiscountDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleDiscountDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deactivates_and_soft_deletes_discount(): void
    {
        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-del-0001-0001-000000000001',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);

        $handler = app(HandleDiscountDeleted::class);
        $handler->handle([
            'event' => 'discount.deleted',
            'uuid' => 'd1e2f3a4-del-0001-0001-000000000001',
        ]);

        $discount->refresh();
        $this->assertFalse($discount->is_posted);
        $this->assertNotNull($discount->deleted_at);
        $this->assertSoftDeleted('discounts', ['external_id' => 'd1e2f3a4-del-0001-0001-000000000001']);
    }

    #[Test]
    public function ignores_unknown_uuid(): void
    {
        $handler = app(HandleDiscountDeleted::class);
        $handler->handle([
            'event' => 'discount.deleted',
            'uuid' => 'nonexistent-discount-uuid',
        ]);

        // Should not throw — just logs and returns
        $this->assertTrue(true);
    }

    #[Test]
    public function missing_uuid_does_nothing(): void
    {
        $handler = app(HandleDiscountDeleted::class);
        $handler->handle([
            'event' => 'discount.deleted',
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function already_deleted_discount_stays_deleted(): void
    {
        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-del-0001-0001-000000000002',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => false,
        ]);
        $discount->delete(); // already soft-deleted

        // Trying to delete again — should not throw
        $handler = app(HandleDiscountDeleted::class);
        $handler->handle([
            'event' => 'discount.deleted',
            'uuid' => 'd1e2f3a4-del-0001-0001-000000000002',
        ]);

        $this->assertSoftDeleted('discounts', ['external_id' => 'd1e2f3a4-del-0001-0001-000000000002']);
    }
}
