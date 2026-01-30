<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Segment;
use App\Models\Product;

class SegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_segment(): void
    {
        $segment = Segment::factory()->create();

        $this->assertDatabaseHas('segments', [
            'id' => $segment->id,
            'name' => $segment->name,
        ]);
    }

    public function test_can_attach_segment_to_product(): void
    {
        $segment = Segment::factory()->create();
        $product = Product::factory()->create();

        $segment->products()->attach($product);

        $this->assertTrue($segment->products->contains($product));
        $this->assertTrue($product->segments->contains($segment));
    }

    public function test_can_retrieve_segments_for_product(): void
    {
        $product = Product::factory()->create();
        $segments = Segment::factory()->count(3)->create();

        $product->segments()->attach($segments);

        $this->assertCount(3, $product->segments);
    }
}
