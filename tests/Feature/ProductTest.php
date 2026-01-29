<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_product()
    {
        $productData = [
            'name' => 'Test Product',
            'base_price' => 100.50,
        ];

        $product = Product::create($productData);

        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals('Test Product', $product->name);
        $this->assertEquals(100.50, $product->base_price);
        
        $this->assertDatabaseHas('products', [
            'name' => 'Test Product',
            'base_price' => 100.50,
        ]);
    }
}
