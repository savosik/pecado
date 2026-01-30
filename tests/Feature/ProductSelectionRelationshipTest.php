<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSelectionRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_attach_products_to_product_selection()
    {
        $selection = ProductSelection::factory()->create();
        $products = Product::factory()->count(3)->create();

        $selection->products()->attach($products);

        $this->assertCount(3, $selection->products);
        $this->assertTrue($selection->products->contains($products[0]));
        $this->assertTrue($selection->products->contains($products[1]));
        $this->assertTrue($selection->products->contains($products[2]));
    }

    public function test_can_retrieve_product_selections_for_product()
    {
        $product = Product::factory()->create();
        $selections = ProductSelection::factory()->count(2)->create();

        $product->productSelections()->attach($selections);

        $this->assertCount(2, $product->productSelections);
        $this->assertTrue($product->productSelections->contains($selections[0]));
        $this->assertTrue($product->productSelections->contains($selections[1]));
    }

    public function test_can_detach_products_from_product_selection()
    {
        $selection = ProductSelection::factory()->create();
        $product = Product::factory()->create();

        $selection->products()->attach($product);
        $this->assertCount(1, $selection->products);

        $selection->products()->detach($product);
        $this->assertCount(0, $selection->fresh()->products);
    }
}
