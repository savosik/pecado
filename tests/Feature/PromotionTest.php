<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Promotion;
use App\Models\Product;

class PromotionTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function test_can_create_promotion(): void
    {
        $promotion = Promotion::factory()->create();

        $this->assertDatabaseHas('promotions', [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'description' => $promotion->description,
        ]);
    }

    public function test_can_attach_promotion_to_product(): void
    {
        $promotion = Promotion::factory()->create();
        $product = Product::factory()->create();

        $promotion->products()->attach($product);

        $this->assertTrue($promotion->products->contains($product));
        $this->assertTrue($product->promotions->contains($promotion));
    }

    public function test_can_retrieve_promotions_for_product(): void
    {
        $product = Product::factory()->create();
        $promotions = Promotion::factory()->count(3)->create();

        $product->promotions()->attach($promotions);

        $this->assertCount(3, $product->promotions);
    }
}
