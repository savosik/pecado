<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SizeChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Str;

class ProductSizeChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_assign_size_chart_to_product()
    {
        $sizeChart = SizeChart::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Size Chart',
            'values' => ['S' => 'Small', 'M' => 'Medium'],
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'base_price' => 100.00,
            'size_chart_id' => $sizeChart->id,
        ]);

        $this->assertEquals($sizeChart->id, $product->size_chart_id);
        $this->assertTrue($product->sizeChart->is($sizeChart));
    }

    public function test_can_retrieve_products_from_size_chart()
    {
        $sizeChart = SizeChart::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Size Chart',
            'values' => ['S' => 'Small', 'M' => 'Medium'],
        ]);

        $product1 = Product::create([
            'name' => 'Product 1',
            'base_price' => 100.00,
            'size_chart_id' => $sizeChart->id,
        ]);

        $product2 = Product::create([
            'name' => 'Product 2',
            'base_price' => 200.00,
            'size_chart_id' => $sizeChart->id,
        ]);

        $this->assertCount(2, $sizeChart->products);
        $this->assertTrue($sizeChart->products->contains($product1));
        $this->assertTrue($sizeChart->products->contains($product2));
    }

    public function test_size_chart_relationship_is_optional()
    {
        $product = Product::create([
            'name' => 'Product Without Size Chart',
            'base_price' => 100.00,
        ]);

        $this->assertNull($product->size_chart_id);
        $this->assertNull($product->sizeChart);
    }
}
