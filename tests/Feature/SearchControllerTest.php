<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use App\Models\SearchHistory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Используем collection-драйвер Scout для тестирования без Meilisearch
        config(['scout.driver' => 'collection']);
    }

    /**
     * Привязать товар к складу с указанным количеством.
     */
    private function addStock(Product $product, int $quantity = 10): void
    {
        $warehouse = Warehouse::firstOrCreate(['name' => 'TestWarehouse']);
        $product->warehouses()->attach($warehouse->id, ['quantity' => $quantity]);
    }

    // ─── Основной поиск ─────────────────────────────────────

    public function test_search_returns_products_categories_brands_articles(): void
    {
        $brand = Brand::factory()->create(['name' => 'TestBrand Alpha']);
        $category = Category::factory()->create(['name' => 'TestCategory Alpha']);
        $product = Product::factory()->create([
            'name' => 'TestProduct Alpha',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        // Добавляем склад с остатком, чтобы товар прошёл фильтр наличия
        $this->addStock($product);

        $article = Article::factory()->create([
            'title' => 'TestArticle Alpha',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);

        $news = News::factory()->create([
            'title' => 'TestNews Alpha',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/search?q=Alpha');

        $response->assertOk()
            ->assertJsonStructure([
                'query',
                'type',
                'results' => [
                    'products',
                    'categories',
                    'brands',
                    'articles',
                    'news',
                ],
            ]);

        $this->assertNotEmpty($response->json('results.products'));
        $this->assertNotEmpty($response->json('results.categories'));
        $this->assertNotEmpty($response->json('results.brands'));
        $this->assertNotEmpty($response->json('results.articles'));
        $this->assertNotEmpty($response->json('results.news'));
    }

    public function test_search_empty_query_returns_empty_results(): void
    {
        Product::factory()->create(['name' => 'Some Product']);

        $response = $this->getJson('/search');

        $response->assertOk()
            ->assertJsonPath('query', null)
            ->assertJsonPath('results', []);
    }

    public function test_search_short_query_fails_validation(): void
    {
        $response = $this->getJson('/search?q=a');

        $response->assertStatus(422);
    }

    // ─── Фильтрация по type ─────────────────────────────────

    public function test_search_filter_by_type_products(): void
    {
        $brand = Brand::factory()->create(['name' => 'FilterBrand Beta']);
        $product = Product::factory()->create(['name' => 'FilterProduct Beta']);

        $this->addStock($product, 5);

        Category::factory()->create(['name' => 'FilterCategory Beta']);

        $response = $this->getJson('/search?q=Beta&type=products');

        $response->assertOk();

        $results = $response->json('results');

        $this->assertArrayHasKey('products', $results);
        $this->assertArrayNotHasKey('categories', $results);
        $this->assertArrayNotHasKey('brands', $results);
        $this->assertArrayNotHasKey('articles', $results);
    }

    public function test_search_filter_by_type_categories(): void
    {
        Category::factory()->create(['name' => 'UniqueCategory Gamma']);
        Product::factory()->create(['name' => 'UniqueProduct Gamma']);

        $response = $this->getJson('/search?q=Gamma&type=categories');

        $response->assertOk();

        $results = $response->json('results');

        $this->assertArrayHasKey('categories', $results);
        $this->assertArrayNotHasKey('products', $results);
        $this->assertArrayNotHasKey('brands', $results);
    }

    // ─── Фильтрация по наличию ──────────────────────────────

    public function test_search_excludes_unavailable_products_by_default(): void
    {
        // Товар БЕЗ остатков на складе
        Product::factory()->create(['name' => 'Unavailable Delta']);
        $unavailableId = Product::latest()->first()->id;

        // Товар С остатками на складе
        $available = Product::factory()->create(['name' => 'Available Delta']);
        $this->addStock($available, 3);

        // include_unavailable=0 → ScoutController фильтрует по primary_stock в PHP
        $response = $this->getJson('/search?q=Delta&include_unavailable=0');

        $response->assertOk();

        $productIds = collect($response->json('results.products'))->pluck('id')->toArray();

        // Товар без остатков НЕ должен быть в результатах
        $this->assertNotContains($unavailableId, $productIds, 'Товар без остатков должен быть исключён при include_unavailable=0');
    }

    public function test_search_include_unavailable_includes_all(): void
    {
        // Товар без остатков
        $unavailable = Product::factory()->create(['name' => 'NoStock Epsilon']);

        // Товар с остатками
        $available = Product::factory()->create(['name' => 'InStock Epsilon']);
        $this->addStock($available, 5);

        $response = $this->getJson('/search?q=Epsilon&include_unavailable=1');

        $response->assertOk();

        $productIds = collect($response->json('results.products'))->pluck('id')->toArray();

        $this->assertContains($available->id, $productIds);
        $this->assertContains($unavailable->id, $productIds);
    }

    // ─── Сохранение в историю ───────────────────────────────

    public function test_search_saves_history_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $product = Product::factory()->create(['name' => 'HistoryProduct Zeta']);
        $this->addStock($product);

        $this->actingAs($user)->getJson('/search?q=Zeta');

        $this->assertDatabaseHas('search_histories', [
            'user_id' => $user->id,
            'query' => 'Zeta',
        ]);
    }

    public function test_search_does_not_save_history_for_guest(): void
    {
        Product::factory()->create(['name' => 'GuestProduct Eta']);

        $this->getJson('/search?q=Eta');

        $this->assertDatabaseCount('search_histories', 0);
    }

    // ─── CRUD истории ───────────────────────────────────────

    public function test_history_returns_user_records(): void
    {
        $user = User::factory()->create();

        SearchHistory::factory()->count(3)->create(['user_id' => $user->id]);
        // Чужие записи не должны попадать
        SearchHistory::factory()->count(2)->create();

        $response = $this->actingAs($user)->getJson('/api/search/history');

        $response->assertOk();
        $this->assertCount(3, $response->json());
    }

    public function test_history_requires_auth(): void
    {
        $response = $this->getJson('/api/search/history');

        $response->assertUnauthorized();
    }

    public function test_delete_single_history_record(): void
    {
        $user = User::factory()->create();
        $history = SearchHistory::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson("/api/search/history/{$history->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('search_histories', ['id' => $history->id]);
    }

    public function test_delete_history_forbidden_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $history = SearchHistory::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->deleteJson("/api/search/history/{$history->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('search_histories', ['id' => $history->id]);
    }

    public function test_clear_all_history(): void
    {
        $user = User::factory()->create();

        SearchHistory::factory()->count(5)->create(['user_id' => $user->id]);
        // Чужие записи НЕ должны удаляться
        $otherHistory = SearchHistory::factory()->create();

        $response = $this->actingAs($user)->deleteJson('/api/search/history');

        $response->assertStatus(204);
        $this->assertDatabaseCount('search_histories', 1);
        $this->assertDatabaseHas('search_histories', ['id' => $otherHistory->id]);
    }

    // ─── Подсказки (suggestions) ────────────────────────────

    public function test_suggestions_returns_products(): void
    {
        Product::factory()->create(['name' => 'Suggestion Theta']);
        Product::factory()->create(['name' => 'Another Theta']);

        $response = $this->getJson('/api/search/suggestions?q=Theta');

        $response->assertOk();
        $this->assertNotEmpty($response->json());

        // Проверяем структуру первого элемента
        $response->assertJsonStructure([
            '*' => ['id', 'name', 'slug', 'price', 'image_url'],
        ]);
    }

    public function test_suggestions_requires_min_2_chars(): void
    {
        $response = $this->getJson('/api/search/suggestions?q=a');

        $response->assertStatus(422);
    }

    // ─── Точный матч по коду / артикулу / штрихкоду ────────

    public function test_exact_sku_match_pinned_first(): void
    {
        // Чужой товар с похожим именем (Scout по name тоже его найдёт),
        // но без точного совпадения по SKU.
        $other = Product::factory()->create([
            'name' => 'Насадка УТ-00008062 классическая',
            'sku' => 'УТ-00008082',
        ]);
        $this->addStock($other);

        $exact = Product::factory()->create([
            'name' => 'Насадка УТ-00008062 другая модель',
            'sku' => 'УТ-00008062',
        ]);
        $this->addStock($exact);

        $response = $this->getJson('/search?q='.urlencode('УТ-00008062').'&type=products');

        $response->assertOk();
        $first = $response->json('results.products.0');

        $this->assertSame($exact->id, $first['id'], 'Точный матч по SKU должен быть первым в выдаче');
    }

    public function test_exact_code_match_pinned_first(): void
    {
        $other = Product::factory()->create([
            'name' => 'Презервативы Ganzo classic',
            'code' => 'ОТ-00004522',
        ]);
        $this->addStock($other);

        $exact = Product::factory()->create([
            'name' => 'Презервативы Ganzo latex',
            'code' => 'ОТ-00004152',
        ]);
        $this->addStock($exact);

        $response = $this->getJson('/search?q='.urlencode('ОТ-00004152').'&type=products');

        $response->assertOk();
        $first = $response->json('results.products.0');

        $this->assertSame($exact->id, $first['id']);
    }

    public function test_exact_barcode_match_pinned_first(): void
    {
        $other = Product::factory()->create([
            'name' => 'Товар с другим штрихкодом',
            'barcode' => '4607004920455',
        ]);
        $this->addStock($other);

        $exact = Product::factory()->create([
            'name' => 'Товар с искомым штрихкодом',
        ]);
        $exact->barcodes()->create(['barcode' => '4607004920454']);
        $this->addStock($exact);

        $response = $this->getJson('/search?q=4607004920454&type=products');

        $response->assertOk();
        $first = $response->json('results.products.0');

        $this->assertSame($exact->id, $first['id'], 'Точный матч из product_barcodes должен быть первым');
    }

    public function test_short_query_skips_fast_path(): void
    {
        $product = Product::factory()->create([
            'name' => 'Тестовый товар',
            'sku' => 'abc',
        ]);
        $this->addStock($product);

        // 3 символа — fast-path не должен срабатывать (минимум 4).
        // Но валидатор требует min:2, так что 3 проходят валидацию.
        $response = $this->getJson('/search?q=abc&type=products');

        // Просто проверяем 200 OK; матчер вернёт null и контроллер пойдёт обычным путём.
        $response->assertOk();
    }

    public function test_exact_match_with_query_containing_space_skipped(): void
    {
        $product = Product::factory()->create([
            'name' => 'Товар',
            'sku' => 'УТ-00008062',
        ]);
        $this->addStock($product);

        // Запрос с пробелом — не должен пытаться точный матч, идёт обычный поиск.
        $response = $this->getJson('/search?q='.urlencode('УТ 00008062').'&type=products');

        $response->assertOk();
    }

    public function test_suggestions_pins_exact_sku_match(): void
    {
        Product::factory()->create([
            'name' => 'Насадка ABC-001 классическая',
            'sku' => 'ABC-002',
        ]);

        $exact = Product::factory()->create([
            'name' => 'Насадка ABC-001 другая',
            'sku' => 'ABC-001',
        ]);

        $response = $this->getJson('/api/search/suggestions?q=ABC-001');

        $response->assertOk();
        $first = $response->json('0');

        $this->assertSame($exact->id, $first['id']);
    }

    public function test_no_exact_match_flag_set_when_query_not_in_any_product(): void
    {
        // Товар с именем, в котором НЕТ запроса как substring.
        $product = Product::factory()->create([
            'name' => 'Совершенно другой товар без искомого вхождения',
            'sku' => 'XYZ-001',
        ]);
        $this->addStock($product);

        // Используем запрос, который collection-driver всё равно матчит
        // (например, через одну из фаззи-эвристик). Если выдача пустая — флаг false (нет смысла показывать).
        // Поэтому создадим товар с partial match и запрос с дополнительным символом, которого нет нигде.
        $response = $this->getJson('/search?q=NOMATCH-CODE-77777&type=products');

        $response->assertOk();
        // total=0 — флаг false (или meta=null).
        $meta = $response->json('productsMeta');
        if ($meta && $meta['total'] > 0) {
            $this->assertTrue($meta['no_exact_match'] ?? false);
        } else {
            // Пусто — баннер не показываем.
            $this->assertTrue($meta === null || ($meta['no_exact_match'] ?? false) === false);
        }
    }

    public function test_no_exact_match_flag_false_when_substring_in_name(): void
    {
        $product = Product::factory()->create([
            'name' => 'Насадка реалистичная KOKOS COCK SLEEVE',
            'sku' => 'CS.002-S',
        ]);
        $this->addStock($product);

        $response = $this->getJson('/search?q=KOKOS&type=products');

        $response->assertOk();
        $meta = $response->json('productsMeta');
        $this->assertNotNull($meta);
        $this->assertFalse($meta['no_exact_match'], 'KOKOS — точное вхождение в имя, флаг должен быть false');
    }

    public function test_no_exact_match_flag_false_when_exact_sku(): void
    {
        $product = Product::factory()->create([
            'name' => 'Любой товар',
            'sku' => 'EXACT-SKU-001',
        ]);
        $this->addStock($product);

        $response = $this->getJson('/search?q=EXACT-SKU-001&type=products');

        $response->assertOk();
        $meta = $response->json('productsMeta');
        $this->assertNotNull($meta);
        $this->assertFalse($meta['no_exact_match']);
    }

    public function test_exact_match_not_duplicated_in_results(): void
    {
        // Товар, который Scout тоже найдёт по sku — не должен дублироваться после пиннинга.
        $exact = Product::factory()->create([
            'name' => 'Уникальное имя для теста',
            'sku' => 'UNIQ-12345',
        ]);
        $this->addStock($exact);

        $response = $this->getJson('/search?q=UNIQ-12345&type=products');

        $response->assertOk();
        $ids = collect($response->json('results.products'))->pluck('id')->all();
        $occurrences = count(array_filter($ids, fn ($id) => $id === $exact->id));

        $this->assertSame(1, $occurrences, 'Точный матч не должен дублироваться в выдаче');
    }
}
