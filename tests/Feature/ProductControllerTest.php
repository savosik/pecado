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
                ->where('seo.title', "Каталог товаров | {$appName}")
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

    public function test_liquidation_renders_with_defect_preset(): void
    {
        $response = $this->get('/products/utsenka');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->has('seo')
            ->where('seo.h1', 'Уценка')
            ->has('initialFilters')
            ->where('initialFilters.in_stock_mode', 'defect')
            ->has('breadcrumbs')
            ->where('seo.canonical', route('products.liquidation'))
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

    // ─── ItemList + BreadcrumbList JSON-LD (F09 + F05) ──────

    public function test_category_listing_exposes_breadcrumb_and_itemlist_jsonld(): void
    {
        $category = Category::create(['name' => 'Вибраторы', 'slug' => 'vibratory-il', 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Товар А']);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Товар Б']);

        $response = $this->withoutVite()->get("/categories/{$category->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            // [0] BreadcrumbList: Каталог → Вибраторы
            ->where('seo.structured_data.0.@type', 'BreadcrumbList')
            ->has('seo.structured_data.0.itemListElement', 2)
            ->where('seo.structured_data.0.itemListElement.0.name', 'Каталог')
            ->where('seo.structured_data.0.itemListElement.1.name', 'Вибраторы')
            // Последний элемент (текущая страница) — без item.
            ->missing('seo.structured_data.0.itemListElement.1.item')
            // [1] ItemList товаров
            ->where('seo.structured_data.1.@type', 'ItemList')
            ->has('seo.structured_data.1.itemListElement', 2)
            ->where('seo.structured_data.1.itemListElement.0.@type', 'ListItem')
            ->where('seo.structured_data.1.itemListElement.0.position', 1)
        );

        // Обе разметки в серверном HTML.
        $html = $response->getContent();
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"ItemList"', $html);
    }

    public function test_brand_listing_exposes_itemlist_jsonld(): void
    {
        $category = Category::create(['name' => 'Кат', 'slug' => 'cat-il', 'is_active' => true]);
        $brand = Brand::factory()->create(['name' => 'Tenga', 'slug' => 'tenga-il']);
        Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id]);

        $response = $this->get("/brands/{$brand->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            // [0] BreadcrumbList: Каталог → Бренды → Tenga
            ->where('seo.structured_data.0.@type', 'BreadcrumbList')
            ->has('seo.structured_data.0.itemListElement', 3)
            ->where('seo.structured_data.0.itemListElement.1.name', 'Бренды')
            // [1] ItemList товаров бренда
            ->where('seo.structured_data.1.@type', 'ItemList')
            ->has('seo.structured_data.1.itemListElement', 1)
        );
    }

    public function test_empty_category_has_breadcrumb_but_no_itemlist(): void
    {
        $category = Category::create(['name' => 'Пусто', 'slug' => 'empty-il', 'is_active' => true]);

        $response = $this->get("/categories/{$category->slug}");

        $response->assertOk();
        // Пустая категория: BreadcrumbList есть, ItemList — нет.
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->has('seo.structured_data', 1)
            ->where('seo.structured_data.0.@type', 'BreadcrumbList')
        );
    }

    public function test_product_page_exposes_product_and_breadcrumb_jsonld(): void
    {
        $category = Category::create(['name' => 'Вибраторы', 'slug' => 'vibr-prod', 'is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Вибратор X',
            'slug' => 'vibrator-x',
        ]);

        $response = $this->withoutVite()->get("/products/{$product->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Show')
            ->where('seo.structured_data.0.@type', 'Product')
            ->where('seo.structured_data.1.@type', 'BreadcrumbList')
            ->where('seo.structured_data.1.itemListElement.0.name', 'Каталог')
            ->where('seo.structured_data.1.itemListElement.1.name', 'Вибраторы')
            ->where('seo.structured_data.1.itemListElement.2.name', 'Вибратор X')
            // Текущая страница (товар) — без item.
            ->missing('seo.structured_data.1.itemListElement.2.item')
        );

        $html = $response->getContent();
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    // ─── смежные категории / перелинковка (F07) ─────────────

    public function test_category_exposes_siblings_with_products(): void
    {
        $parent = Category::create(['name' => 'Раздел', 'slug' => 'p7', 'is_active' => true]);
        $current = Category::create(['name' => 'Текущая', 'slug' => 'cur7', 'parent_id' => $parent->id, 'is_active' => true]);
        $sibA = Category::create(['name' => 'Смежная A', 'slug' => 'sib-a', 'parent_id' => $parent->id, 'is_active' => true]);
        $sibB = Category::create(['name' => 'Смежная B', 'slug' => 'sib-b', 'parent_id' => $parent->id, 'is_active' => true]);
        Category::create(['name' => 'Смежная пустая', 'slug' => 'sib-empty', 'parent_id' => $parent->id, 'is_active' => true]);
        Category::create(['name' => 'Смежная неактивная', 'slug' => 'sib-off', 'parent_id' => $parent->id, 'is_active' => false]);

        Product::factory()->create(['category_id' => $current->id]);
        Product::factory()->create(['category_id' => $sibA->id]);
        Product::factory()->create(['category_id' => $sibB->id]);

        $response = $this->get("/categories/{$current->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            // Смежные с товарами: A и B; текущая, пустая и неактивная исключены.
            ->has('categorySiblings', 2)
            ->where('categorySiblings.0.slug', 'sib-a')
            ->where('categorySiblings.1.slug', 'sib-b')
            ->where('categorySiblings.0.count', 1)
        );
    }

    public function test_root_category_siblings_are_other_roots(): void
    {
        $current = Category::create(['name' => 'Корень 1', 'slug' => 'root-1', 'is_active' => true]);
        $otherRoot = Category::create(['name' => 'Корень 2', 'slug' => 'root-2', 'is_active' => true]);
        Product::factory()->create(['category_id' => $current->id]);
        Product::factory()->create(['category_id' => $otherRoot->id]);

        $response = $this->get("/categories/{$current->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->has('categorySiblings', 1)
            ->where('categorySiblings.0.slug', 'root-2')
        );
    }

    // ─── SEO-текст категории (F04) ──────────────────────────

    public function test_category_passes_seo_text_props(): void
    {
        $category = Category::create([
            'name' => 'Вибраторы',
            'slug' => 'vibr-seo',
            'is_active' => true,
            'short_description' => 'Краткое интро.',
            'description' => '<p>Полный SEO-текст про вибраторы.</p>',
        ]);

        $response = $this->get("/categories/{$category->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            // Короткий интро — вверху (CatalogHeader), полный текст — в нижнем SEO-блоке.
            ->where('pageIntro', 'Краткое интро.')
            ->where('pageDescription', '<p>Полный SEO-текст про вибраторы.</p>')
        );
    }

    // ─── canonical / noindex фильтров и пагинации (F06) ─────

    public function test_clean_category_is_indexable_without_robots(): void
    {
        $category = Category::create(['name' => 'Кат', 'slug' => 'clean-cat', 'is_active' => true]);

        $response = $this->get("/categories/{$category->slug}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->missing('seo.robots')
            ->where('seo.canonical', route('products.category', $category))
        );
    }

    public function test_filtered_category_gets_noindex_with_clean_canonical(): void
    {
        $category = Category::create(['name' => 'Кат', 'slug' => 'filtered-cat', 'is_active' => true]);

        $response = $this->withoutVite()->get("/categories/{$category->slug}?sort=price_asc&price_min=100");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->where('seo.robots', 'noindex, follow')
            // canonical остаётся чистым (без query).
            ->where('seo.canonical', route('products.category', $category))
        );
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $response->getContent());
    }

    public function test_paginated_category_gets_noindex(): void
    {
        $category = Category::create(['name' => 'Кат', 'slug' => 'paged-cat', 'is_active' => true]);

        // page=1 — каноничная, без noindex
        $this->get("/categories/{$category->slug}?page=1")
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('seo.robots'));

        // page=2 — noindex
        $this->get("/categories/{$category->slug}?page=2")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('seo.robots', 'noindex, follow'));
    }

    public function test_filtered_brand_gets_noindex(): void
    {
        $brand = Brand::factory()->create(['name' => 'Tenga', 'slug' => 'tenga-f']);

        $response = $this->get("/brands/{$brand->slug}?in_stock_mode=in_stock");

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Products/Index')
            ->where('seo.robots', 'noindex, follow')
            ->where('seo.canonical', route('products.brand', $brand))
        );
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
                ->where('seo.title', "Избранные товары | {$appName}")
                ->where('seo.canonical', route('products.favorites'))
                ->where('seo.url', route('products.favorites'))
                ->where('initialFilters.in_favourites', 1);
        });
    }
}
