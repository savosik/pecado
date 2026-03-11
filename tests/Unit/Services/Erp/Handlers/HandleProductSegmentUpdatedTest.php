<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\ProductSegment;
use App\Services\Erp\Handlers\HandleProductSegmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleProductSegmentUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function updates_existing_segment_name_and_products(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'prod-upd-001']);
        $product2 = Product::factory()->create(['external_id' => 'prod-upd-002']);

        $segment = ProductSegment::create([
            'uuid' => 'seg-upd-001',
            'name' => 'Старое название',
        ]);
        $segment->products()->attach($product1->id);

        $handler = app(HandleProductSegmentUpdated::class);
        $handler->handle([
            'event' => 'product_segment.updated',
            'uuid' => 'seg-upd-001',
            'name' => 'Новое название',
            'product_uuids' => ['prod-upd-002'],
        ]);

        $segment->refresh();
        $this->assertEquals('Новое название', $segment->name);
        $this->assertCount(1, $segment->products);
        $this->assertTrue($segment->products->contains($product2));
        $this->assertFalse($segment->products->contains($product1));
    }

    #[Test]
    public function creates_segment_if_not_exists(): void
    {
        $handler = app(HandleProductSegmentUpdated::class);
        $handler->handle([
            'event' => 'product_segment.updated',
            'uuid' => 'seg-upd-new',
            'name' => 'Новый сегмент',
            'product_uuids' => [],
        ]);

        $this->assertDatabaseHas('product_segments', [
            'uuid' => 'seg-upd-new',
            'name' => 'Новый сегмент',
        ]);
    }

    #[Test]
    public function missing_uuid_does_nothing(): void
    {
        $handler = app(HandleProductSegmentUpdated::class);
        $handler->handle([
            'event' => 'product_segment.updated',
            'name' => 'Без UUID',
        ]);

        $this->assertDatabaseCount('product_segments', 0);
    }
}
