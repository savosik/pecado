<?php

namespace Tests\Feature\Api\Content;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test', ['*'])->plainTextToken;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ── Products ────────────────────────────────────────

    public function test_products_index_returns_paginated(): void
    {
        Product::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => ['id', 'name', 'slug']],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_products_filter_by_brand(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->count(2)->create(['brand_id' => $brand->id]);
        Product::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/products?brand_ids[]={$brand->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_products_filter_by_price(): void
    {
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 500]);
        Product::factory()->create(['base_price' => 1000]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/products?price_min=200&price_max=600')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_products_filter_new(): void
    {
        Product::factory()->create(['is_new' => true]);
        Product::factory()->create(['is_new' => false]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/products?is_new=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_products_show(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_products_post_not_allowed(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/products', ['name' => 'hack'])
            ->assertStatus(405);
    }

    // ── Brands ─────────────────────────────────────────

    public function test_brands_index(): void
    {
        Brand::factory()->count(5)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/brands')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    public function test_brands_search(): void
    {
        Brand::factory()->create(['name' => 'Satisfyer']);
        Brand::factory()->create(['name' => 'Lelo']);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/brands?search=Satis')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_brands_show(): void
    {
        $brand = Brand::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $brand->id)
            ->assertJsonStructure(['data' => ['products_count']]);
    }

    // ── Categories ─────────────────────────────────────

    public function test_categories_index_tree(): void
    {
        Category::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/categories')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_categories_flat_search(): void
    {
        Category::factory()->create(['name' => 'Вибраторы', 'is_active' => true]);
        Category::factory()->create(['name' => 'Массажёры', 'is_active' => true]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/categories?search=Вибр')
            ->assertOk();
    }

    public function test_categories_show_with_children(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/categories/{$parent->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['children', 'products_count', 'ancestors']]);
    }

    // ── Product Selections ─────────────────────────────

    public function test_product_selection_crud(): void
    {
        // Store
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/product-selections', [
                'name' => 'Лучшее за неделю',
            ]);
        $response->assertCreated();
        $id = $response->json('data.id');

        // Index
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/product-selections')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Show
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/product-selections/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Лучшее за неделю');

        // Update
        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/product-selections/{$id}", ['name' => 'Обновлённая'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Обновлённая');

        // Destroy
        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/product-selections/{$id}")
            ->assertNoContent();
    }

    public function test_product_selection_sync_products(): void
    {
        $selection = ProductSelection::factory()->create();
        $products = Product::factory()->count(4)->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/product-selections/{$selection->id}/products", [
                'product_ids' => $products->pluck('id')->toArray(),
                'featured_ids' => [$products->first()->id],
            ])
            ->assertOk()
            ->assertJsonPath('products_count', 4);
    }
}
