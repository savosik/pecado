<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\ProductSegment;
use App\Services\Erp\Handlers\HandleProductSegmentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleProductSegmentCreatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_segment_with_products(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'prod-uuid-001']);
        $product2 = Product::factory()->create(['external_id' => 'prod-uuid-002']);

        $handler = app(HandleProductSegmentCreated::class);
        $handler->handle([
            'event' => 'product_segment.created',
            'uuid' => 'seg-prod-001',
            'name' => 'Лубриканты',
            'product_uuids' => ['prod-uuid-001', 'prod-uuid-002'],
        ]);

        $this->assertDatabaseHas('product_segments', [
            'uuid' => 'seg-prod-001',
            'name' => 'Лубриканты',
        ]);

        $segment = ProductSegment::where('uuid', 'seg-prod-001')->first();
        $this->assertCount(2, $segment->products);
        $this->assertTrue($segment->products->contains($product1));
        $this->assertTrue($segment->products->contains($product2));
    }

    #[Test]
    public function idempotent_updates_existing_segment(): void
    {
        ProductSegment::create([
            'uuid' => 'seg-prod-002',
            'name' => 'Старое название',
        ]);

        $handler = app(HandleProductSegmentCreated::class);
        $handler->handle([
            'event' => 'product_segment.created',
            'uuid' => 'seg-prod-002',
            'name' => 'Новое название',
            'product_uuids' => [],
        ]);

        $this->assertDatabaseHas('product_segments', [
            'uuid' => 'seg-prod-002',
            'name' => 'Новое название',
        ]);

        $this->assertEquals(1, ProductSegment::where('uuid', 'seg-prod-002')->count());
    }

    #[Test]
    public function ignores_unknown_product_uuids(): void
    {
        $product = Product::factory()->create(['external_id' => 'known-prod-uuid']);

        $handler = app(HandleProductSegmentCreated::class);
        $handler->handle([
            'event' => 'product_segment.created',
            'uuid' => 'seg-prod-003',
            'name' => 'Тестовый сегмент',
            'product_uuids' => ['known-prod-uuid', 'unknown-prod-uuid'],
        ]);

        $segment = ProductSegment::where('uuid', 'seg-prod-003')->first();
        $this->assertNotNull($segment);
        $this->assertCount(1, $segment->products);
        $this->assertTrue($segment->products->contains($product));
    }

    #[Test]
    public function missing_uuid_does_not_create_segment(): void
    {
        $handler = app(HandleProductSegmentCreated::class);
        $handler->handle([
            'event' => 'product_segment.created',
            'name' => 'Без UUID',
            'product_uuids' => [],
        ]);

        $this->assertDatabaseCount('product_segments', 0);
    }

    #[Test]
    public function missing_name_does_not_create_segment(): void
    {
        $handler = app(HandleProductSegmentCreated::class);
        $handler->handle([
            'event' => 'product_segment.created',
            'uuid' => 'seg-prod-005',
            'product_uuids' => [],
        ]);

        $this->assertDatabaseCount('product_segments', 0);
    }

    #[Test]
    public function syncs_products_on_repeated_call(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'prod-sync-001']);
        $product2 = Product::factory()->create(['external_id' => 'prod-sync-002']);

        $handler = app(HandleProductSegmentCreated::class);

        // Первый вызов: только prod1
        $handler->handle([
            'uuid' => 'seg-prod-006',
            'name' => 'Тест синхронизации',
            'product_uuids' => ['prod-sync-001'],
        ]);

        $segment = ProductSegment::where('uuid', 'seg-prod-006')->first();
        $this->assertCount(1, $segment->products);

        // Второй вызов: только prod2
        $handler->handle([
            'uuid' => 'seg-prod-006',
            'name' => 'Тест синхронизации',
            'product_uuids' => ['prod-sync-002'],
        ]);

        $segment->refresh();
        $this->assertCount(1, $segment->products);
        $this->assertTrue($segment->products->contains($product2));
        $this->assertFalse($segment->products->contains($product1));
    }
}
