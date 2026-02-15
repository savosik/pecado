<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── index ──────────────────────────────────────────────

    public function test_index_renders_inertia_page(): void
    {
        $response = $this->get('/products');

        $response->assertOk();
        $appName = config('app.name');
        $response->assertInertia(function (AssertableInertia $page) use ($appName) {
            $page->component('User/Products/Index')
                ->has('seo')
                ->where('seo.title', "Каталог товаров — {$appName}")
                ->where('seo.h1', 'Каталог товаров');
        });
    }

    // ─── byBrand ────────────────────────────────────────────

    public function test_by_brand_renders_with_brand_preset(): void
    {
        $brand = Brand::factory()->create(['name' => 'TestBrand', 'slug' => 'testbrand']);

        $response = $this->get('/brands/testbrand');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) =>
            $page->component('User/Products/Index')
                ->has('seo')
                ->has('initialFilters')
                ->where('initialFilters.brand_ids', [$brand->id])
                ->has('brand')
                ->where('brand.id', $brand->id)
                ->where('brand.name', 'TestBrand')
                ->where('brand.slug', 'testbrand')
                ->has('breadcrumbs')
        );
    }

    public function test_by_brand_404_for_unknown_slug(): void
    {
        $response = $this->get('/brands/unknown-brand-slug');

        $response->assertNotFound();
    }

    // ─── byCategory ─────────────────────────────────────────

    public function test_by_category_renders_with_category_preset(): void
    {
        $parent = Category::factory()->create(['name' => 'Родительская', 'slug' => 'parent']);
        $child = Category::factory()->create([
            'name'      => 'Дочерняя',
            'slug'      => 'child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->get('/categories/child');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) =>
            $page->component('User/Products/Index')
                ->has('seo')
                ->where('initialFilters.category_id', $child->id)
                ->has('category')
                ->where('category.id', $child->id)
                ->where('category.name', 'Дочерняя')
                ->has('breadcrumbs', 3) // Каталог → Родительская → Дочерняя
                ->where('breadcrumbs.0.label', 'Каталог')
                ->where('breadcrumbs.1.label', 'Родительская')
                ->where('breadcrumbs.2.label', 'Дочерняя')
                ->where('breadcrumbs.2.url', null)
        );
    }

    public function test_by_category_404_for_unknown_slug(): void
    {
        $response = $this->get('/categories/unknown-category-slug');

        $response->assertNotFound();
    }

    // ─── bySelection ────────────────────────────────────────

    public function test_by_selection_renders_with_selection_preset(): void
    {
        $selection = ProductSelection::factory()->create([
            'name' => 'Тест подборка',
            'slug' => 'test-selection',
        ]);

        $response = $this->get('/collections/test-selection');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) =>
            $page->component('User/Products/Index')
                ->has('seo')
                ->where('initialFilters.collection_ids', [$selection->id])
                ->has('selection')
                ->where('selection.id', $selection->id)
                ->where('selection.name', 'Тест подборка')
                ->has('breadcrumbs')
        );
    }

    public function test_by_selection_404_for_unknown_slug(): void
    {
        $response = $this->get('/collections/unknown-selection-slug');

        $response->assertNotFound();
    }

    // ─── favorites ──────────────────────────────────────────

    public function test_favorites_requires_auth(): void
    {
        $response = $this->get('/products/favorites');

        $response->assertRedirect('/login');
    }

    public function test_favorites_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/products/favorites');

        $appName = config('app.name');
        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($appName) {
            $page->component('User/Products/Index')
                ->has('seo')
                ->where('seo.title', "Избранные товары — {$appName}")
                ->where('initialFilters.in_favourites', 1);
        });
    }
}
