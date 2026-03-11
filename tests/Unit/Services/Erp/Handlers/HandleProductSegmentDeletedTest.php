<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\ProductSegment;
use App\Services\Erp\Handlers\HandleProductSegmentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleProductSegmentDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deletes_existing_segment(): void
    {
        ProductSegment::create([
            'uuid' => 'seg-del-001',
            'name' => 'Для удаления',
        ]);

        $handler = app(HandleProductSegmentDeleted::class);
        $handler->handle([
            'event' => 'product_segment.deleted',
            'uuid' => 'seg-del-001',
        ]);

        $this->assertDatabaseMissing('product_segments', [
            'uuid' => 'seg-del-001',
        ]);
    }

    #[Test]
    public function does_not_fail_if_segment_not_found(): void
    {
        $handler = app(HandleProductSegmentDeleted::class);

        // Не должно бросать исключение
        $handler->handle([
            'event' => 'product_segment.deleted',
            'uuid' => 'non-existent-uuid',
        ]);

        $this->assertDatabaseCount('product_segments', 0);
    }

    #[Test]
    public function deletes_pivot_records_with_segment(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-del-001']);

        $segment = ProductSegment::create([
            'uuid' => 'seg-del-002',
            'name' => 'С товарами',
        ]);
        $segment->products()->attach($product->id);

        $this->assertDatabaseHas('product_product_segment', [
            'product_segment_id' => $segment->id,
            'product_id' => $product->id,
        ]);

        $handler = app(HandleProductSegmentDeleted::class);
        $handler->handle([
            'event' => 'product_segment.deleted',
            'uuid' => 'seg-del-002',
        ]);

        $this->assertDatabaseMissing('product_segments', ['uuid' => 'seg-del-002']);
        $this->assertDatabaseMissing('product_product_segment', [
            'product_segment_id' => $segment->id,
        ]);
    }

    #[Test]
    public function missing_uuid_does_nothing(): void
    {
        ProductSegment::create([
            'uuid' => 'seg-del-003',
            'name' => 'Не удалять',
        ]);

        $handler = app(HandleProductSegmentDeleted::class);
        $handler->handle([
            'event' => 'product_segment.deleted',
        ]);

        $this->assertDatabaseHas('product_segments', ['uuid' => 'seg-del-003']);
    }
}
