<?php

namespace Tests\Feature\Console;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSitemapTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sitemap_includes_active_entities_with_app_host(): void
    {
        $category = Category::create(['name' => 'Видимая', 'slug' => 'visible-cat', 'is_active' => true]);
        $brand = Brand::factory()->create(['slug' => 'visible-brand']);
        $product = Product::factory()->create(['slug' => 'visible-product', 'hidden' => false]);

        $path = tempnam(sys_get_temp_dir(), 'sitemap_').'.xml';
        $this->artisan('sitemap:generate', ['--path' => $path])->assertSuccessful();

        $xml = file_get_contents($path);
        @unlink($path);

        // Статические + сущности с хостом из config('app.url') (через route()).
        $this->assertStringContainsString(route('home'), $xml);
        $this->assertStringContainsString(route('products.category', $category->slug), $xml);
        $this->assertStringContainsString(route('products.brand', $brand->slug), $xml);
        $this->assertStringContainsString(route('products.show', $product->slug), $xml);
        $this->assertStringContainsString('<urlset', $xml);
    }

    #[Test]
    public function sitemap_excludes_hidden_products_and_inactive_categories(): void
    {
        Category::create(['name' => 'Скрытая', 'slug' => 'inactive-cat', 'is_active' => false]);
        Product::factory()->create(['slug' => 'hidden-product', 'hidden' => true]);

        $path = tempnam(sys_get_temp_dir(), 'sitemap_').'.xml';
        $this->artisan('sitemap:generate', ['--path' => $path])->assertSuccessful();

        $xml = file_get_contents($path);
        @unlink($path);

        $this->assertStringNotContainsString('hidden-product', $xml);
        $this->assertStringNotContainsString('inactive-cat', $xml);
    }
}
