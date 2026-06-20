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
                ->where('seo.h1', 'Каталог товаров')
                ->where('seo.canonical', route('products.index'))
                ->where('seo.url', route('products.index'));
        });
    }

    // ─── byBrand ────────────────────────────────────────────

    public function test_by_brand_renders_with_brand_preset(): void
    {
        $brand = Brand::factory()->create(['name' => 'TestBrand', 'slug' => 'testbrand']);

        $response = $this->get('/brands/testbrand');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->has('seo')
            ->has('initialFilters')
            ->where('initialFilters.brand_ids', [$brand->id])
            ->has('brand')
            ->where('brand.id', $brand->id)
            ->where('brand.name', 'TestBrand')
            ->where('brand.slug', 'testbrand')
            ->has('breadcrumbs')
            ->where('seo.canonical', route('products.brand', $brand))
            ->where('seo.url', route('products.brand', $brand))
        );
    }

    public function test_by_brand_404_for_unknown_slug(): void
    {
        $response = $this->get('/brands/unknown-brand-slug');

        $response->assertNotFound();
    }

    // ─── meta keywords (F03) ────────────────────────────────

    public function test_category_with_meta_keywords_passes_seo_keywords(): void
    {
        $category = Category::factory()->create([
            'name' => 'Вибраторы',
            'slug' => 'vibratory-kw',
            'meta_keywords' => 'купить вибратор, женский вибратор',
        ]);

        $response = $this->get("/categories/{$category->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->where('seo.keywords', 'купить вибратор, женский вибратор')
        );
    }

    public function test_category_without_meta_keywords_has_null_seo_keywords(): void
    {
        $category = Category::factory()->create(['name' => 'Без ключей', 'slug' => 'no-kw']);

        $response = $this->get("/categories/{$category->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->where('seo.keywords', null)
        );
    }

    public function test_brand_with_meta_keywords_passes_seo_keywords(): void
    {
        $brand = Brand::factory()->create([
            'name' => 'Tenga',
            'slug' => 'tenga-kw',
            'meta_keywords' => 'tenga купить, мастурбатор tenga',
        ]);

        $response = $this->get("/brands/{$brand->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->where('seo.keywords', 'tenga купить, мастурбатор tenga')
        );
    }

    public function test_category_meta_rendered_server_side_in_html(): void
    {
        $category = Category::factory()->create([
            'name' => 'Вибраторы',
            'slug' => 'vibratory-html',
            'meta_title' => 'Вибраторы | Pecado',
            'meta_description' => 'Описание вибраторов',
            'meta_keywords' => 'купить вибратор, женский вибратор',
        ]);

        $response = $this->withoutVite()->get("/categories/{$category->slug}");

        $response->assertOk();
        // Мета должна присутствовать прямо в HTML (view-source), а не только в Inertia-payload.
        $response->assertSee('<title inertia>Вибраторы | Pecado</title>', false);
        $response->assertSee('<meta name="description" content="Описание вибраторов">', false);
        $response->assertSee('<meta name="keywords" content="купить вибратор, женский вибратор">', false);
        $response->assertSee('<link rel="canonical"', false);
    }

    public function test_category_without_keywords_omits_keywords_tag_in_html(): void
    {
        $category = Category::factory()->create(['name' => 'Без ключей', 'slug' => 'no-kw-html']);

        $response = $this->withoutVite()->get("/categories/{$category->slug}");

        $response->assertOk();
        $response->assertDontSee('<meta name="keywords"', false);
    }

    // ─── byCategory ─────────────────────────────────────────

    public function test_by_category_renders_with_category_preset(): void
    {
        $parent = Category::factory()->create(['name' => 'Родительская', 'slug' => 'parent']);
        $child = Category::factory()->create([
            'name' => 'Дочерняя',
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->get('/categories/child');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
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
            ->where('seo.canonical', route('products.category', $child))
            ->where('seo.url', route('products.category', $child))
        );
    }

    public function test_by_category_404_for_unknown_slug(): void
    {
        $response = $this->get('/categories/unknown-category-slug');

        $response->assertNotFound();
    }

    public function test_by_category_exposes_children_chips_with_subtree_counts(): void
    {
        // Иерархия: parent → [child_a → grandchild_a1] и [child_b]
        $parent = Category::create(['name' => 'Раздел', 'slug' => 'razdel', 'is_active' => true]);
        $childA = Category::create(['name' => 'A', 'slug' => 'cat-a', 'parent_id' => $parent->id, 'is_active' => true]);
        $grandA1 = Category::create(['name' => 'A1', 'slug' => 'cat-a1', 'parent_id' => $childA->id, 'is_active' => true]);
        $childB = Category::create(['name' => 'B', 'slug' => 'cat-b', 'parent_id' => $parent->id, 'is_active' => true]);
        Category::create(['name' => 'C-empty', 'slug' => 'cat-c', 'parent_id' => $parent->id, 'is_active' => true]);
        Category::create(['name' => 'D-inactive', 'slug' => 'cat-d', 'parent_id' => $parent->id, 'is_active' => false]);

        // 2 товара в поддереве A (один в A, один в A1), 1 товар в B, 0 в C/D.
        Product::factory()->create(['category_id' => $childA->id]);
        Product::factory()->create(['category_id' => $grandA1->id]);
        Product::factory()->create(['category_id' => $childB->id]);

        $response = $this->get('/categories/razdel');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            // Только активные дети с count > 0; неактивная D и пустая C исключены
            ->has('categoryChildren', 2)
            ->where('categoryChildren.0.slug', 'cat-a')
            ->where('categoryChildren.0.count', 2)
            ->where('categoryChildren.1.slug', 'cat-b')
            ->where('categoryChildren.1.count', 1)
        );
    }

    public function test_by_category_returns_empty_children_for_leaf_category(): void
    {
        $leaf = Category::create(['name' => 'Лист', 'slug' => 'leaf', 'is_active' => true]);
        Product::factory()->create(['category_id' => $leaf->id]);

        $response = $this->get('/categories/leaf');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->where('categoryChildren', [])
        );
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
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->has('seo')
            ->where('initialFilters.collection_ids', [$selection->id])
            ->has('selection')
            ->where('selection.id', $selection->id)
            ->where('selection.name', 'Тест подборка')
            ->has('breadcrumbs')
            ->where('seo.canonical', route('products.selection', $selection))
            ->where('seo.url', route('products.selection', $selection))
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
                ->where('seo.canonical', route('products.favorites'))
                ->where('seo.url', route('products.favorites'))
                ->where('initialFilters.in_favourites', 1);
        });
    }
}
