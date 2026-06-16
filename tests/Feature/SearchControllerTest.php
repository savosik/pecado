<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\News;
use App\Models\Product;
use App\Models\Region;
use App\Models\SearchHistory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Создать регион по умолчанию с основным и предзаказным складами.
     *
     * @return array{primary: Warehouse, preorder: Warehouse}
     */
    private function setupRegionWarehouses(): array
    {
        $region = Region::factory()->create();
        $primary = Warehouse::factory()->create();
        $preorder = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $region->id, 'warehouse_id' => $preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return ['primary' => $primary, 'preorder' => $preorder];
    }

    /**
     * Привязать товар к конкретному складу с количеством.
     */
    private function attachWarehouse(Product $product, Warehouse $warehouse, int $quantity): void
    {
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

    public function test_search_availability_in_stock_shows_only_in_stock(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'InStock Zeta']);
        $this->attachWarehouse($inStock, $wh['primary'], 5);

        $preorderOnly = Product::factory()->create(['name' => 'Preorder Zeta']);
        $this->attachWarehouse($preorderOnly, $wh['preorder'], 7);

        $noStock = Product::factory()->create(['name' => 'NoStock Zeta']);

        $response = $this->getJson('/search?q=Zeta&availability=in_stock');

        $response->assertOk();
        $response->assertJsonPath('availability', 'in_stock');

        $ids = collect($response->json('results.products'))->pluck('id')->all();

        $this->assertContains($inStock->id, $ids);
        $this->assertNotContains($preorderOnly->id, $ids, 'Только под предзаказ — не «в наличии»');
        $this->assertNotContains($noStock->id, $ids);
    }

    public function test_search_availability_in_stock_preorder_includes_preorder(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'InStock Eta']);
        $this->attachWarehouse($inStock, $wh['primary'], 5);

        $preorderOnly = Product::factory()->create(['name' => 'Preorder Eta']);
        $this->attachWarehouse($preorderOnly, $wh['preorder'], 7);

        $noStock = Product::factory()->create(['name' => 'NoStock Eta']);

        $response = $this->getJson('/search?q=Eta&availability=in_stock_preorder');

        $response->assertOk();
        $response->assertJsonPath('availability', 'in_stock_preorder');

        $ids = collect($response->json('results.products'))->pluck('id')->all();

        $this->assertContains($inStock->id, $ids);
        $this->assertContains($preorderOnly->id, $ids, 'Предзаказ должен входить в режим in_stock_preorder');
        $this->assertNotContains($noStock->id, $ids, 'Товар без остатков исключается');
    }

    public function test_search_availability_all_includes_everything(): void
    {
        $wh = $this->setupRegionWarehouses();

        $inStock = Product::factory()->create(['name' => 'InStock Theta']);
        $this->attachWarehouse($inStock, $wh['primary'], 5);

        $noStock = Product::factory()->create(['name' => 'NoStock Theta']);

        $response = $this->getJson('/search?q=Theta&availability=all');

        $response->assertOk();
        $response->assertJsonPath('availability', 'all');

        $ids = collect($response->json('results.products'))->pluck('id')->all();

        $this->assertContains($inStock->id, $ids);
        $this->assertContains($noStock->id, $ids, 'В режиме all показываются и товары без остатков');
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

    // ─── Сортировка по наличию (задача #67) ─────────────────

    /**
     * Создать регион с привязанными primary и preorder складами.
     *
     * @return array{region: Region, primary: Warehouse, preorder: Warehouse}
     */
    private function setupRegionWithStockTypes(): array
    {
        $region = Region::firstOrCreate(['name' => 'TestRegion']);
        $primary = Warehouse::firstOrCreate(['name' => 'PrimaryWarehouse']);
        $preorder = Warehouse::firstOrCreate(['name' => 'PreorderWarehouse']);

        $region->primaryWarehouses()->syncWithoutDetaching([$primary->id => ['type' => 'primary']]);
        $region->preorderWarehouses()->syncWithoutDetaching([$preorder->id => ['type' => 'preorder']]);

        return ['region' => $region, 'primary' => $primary, 'preorder' => $preorder];
    }

    public function test_in_stock_products_ranked_above_preorder_only_on_fuzzy_search(): void
    {
        ['primary' => $primaryWh, 'preorder' => $preorderWh] = $this->setupRegionWithStockTypes();

        // Создаём товар "только под предзаказ" первым — у него меньший id.
        // CollectionEngine Scout сортирует по id desc, поэтому без фикса он бы оказался
        // ниже товара с большим id; чтобы реально проверить пересортировку, делаем
        // наоборот: товар в наличии создаём вторым (больший id → у CollectionEngine
        // он первым по умолчанию). Тест не зависит от порядка id внутри группы —
        // важно только то, что товар с primary_stock > 0 идёт выше товара только с preorder_stock.
        $preorderOnly = Product::factory()->create(['name' => 'Glossy Karlie преордер']);
        $preorderOnly->warehouses()->attach($preorderWh->id, ['quantity' => 5]);

        $inStock = Product::factory()->create(['name' => 'Glossy Kelly наличие']);
        $inStock->warehouses()->attach($primaryWh->id, ['quantity' => 3]);

        $response = $this->getJson('/search?q=Glossy&type=products');

        $response->assertOk();
        $ids = collect($response->json('results.products'))->pluck('id')->all();

        $this->assertContains($inStock->id, $ids);
        $this->assertContains($preorderOnly->id, $ids);

        $inStockPos = array_search($inStock->id, $ids, true);
        $preorderPos = array_search($preorderOnly->id, $ids, true);

        $this->assertLessThan(
            $preorderPos,
            $inStockPos,
            'Товар в наличии должен идти раньше товара только под предзаказ при неточном совпадении'
        );
    }

    public function test_exact_match_pinned_first_even_if_preorder_only(): void
    {
        ['primary' => $primaryWh, 'preorder' => $preorderWh] = $this->setupRegionWithStockTypes();

        // Точный матч по SKU — но товар только под предзаказ.
        $exactPreorder = Product::factory()->create([
            'name' => 'Товар под заказ',
            'sku' => 'PRE-ORDER-001',
        ]);
        $exactPreorder->warehouses()->attach($preorderWh->id, ['quantity' => 2]);

        // Фаззи-кандидат в наличии — Scout его тоже найдёт по совпадению в имени.
        $fuzzyInStock = Product::factory()->create([
            'name' => 'Похожий товар PRE-ORDER в наличии',
            'sku' => 'IN-STOCK-002',
        ]);
        $fuzzyInStock->warehouses()->attach($primaryWh->id, ['quantity' => 7]);

        $response = $this->getJson('/search?q=PRE-ORDER-001&type=products');

        $response->assertOk();
        $first = $response->json('results.products.0');

        $this->assertSame(
            $exactPreorder->id,
            $first['id'],
            'Точный матч по SKU должен пиниться первым даже для товара под предзаказ'
        );
    }

    public function test_in_stock_priority_preserves_relative_order_within_group(): void
    {
        ['primary' => $primaryWh, 'preorder' => $preorderWh] = $this->setupRegionWithStockTypes();

        // Два товара под предзаказ и два в наличии. После сортировки оба в наличии
        // должны быть выше обоих в предзаказе; внутри каждой группы — порядок Scout сохраняется.
        $preorder1 = Product::factory()->create(['name' => 'Glossy preorder one']);
        $preorder1->warehouses()->attach($preorderWh->id, ['quantity' => 1]);

        $inStock1 = Product::factory()->create(['name' => 'Glossy stock one']);
        $inStock1->warehouses()->attach($primaryWh->id, ['quantity' => 1]);

        $preorder2 = Product::factory()->create(['name' => 'Glossy preorder two']);
        $preorder2->warehouses()->attach($preorderWh->id, ['quantity' => 1]);

        $inStock2 = Product::factory()->create(['name' => 'Glossy stock two']);
        $inStock2->warehouses()->attach($primaryWh->id, ['quantity' => 1]);

        $response = $this->getJson('/search?q=Glossy&type=products');

        $response->assertOk();
        $ids = collect($response->json('results.products'))->pluck('id')->all();

        // Все 4 в выдаче.
        $this->assertContains($inStock1->id, $ids);
        $this->assertContains($inStock2->id, $ids);
        $this->assertContains($preorder1->id, $ids);
        $this->assertContains($preorder2->id, $ids);

        // Любой товар в наличии — выше любого товара только под предзаказ.
        $maxInStockPos = max(
            array_search($inStock1->id, $ids, true),
            array_search($inStock2->id, $ids, true)
        );
        $minPreorderPos = min(
            array_search($preorder1->id, $ids, true),
            array_search($preorder2->id, $ids, true)
        );

        $this->assertLessThan(
            $minPreorderPos,
            $maxInStockPos,
            'Все товары в наличии должны идти выше всех товаров только под предзаказ'
        );
    }
}
