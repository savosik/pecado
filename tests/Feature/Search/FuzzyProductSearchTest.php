<?php

namespace Tests\Feature\Search;

use App\Models\Brand;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FuzzyProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Brand $naikBrand;

    /**
     * Товар c названием без слова «Найк», но связанный с брендом «Найк».
     * Запрос «nayk» (латиница, транслит) НЕ попадает в LIKE по русскому
     * `name`/`brand.name`, но индекс Scout содержит `brand_translit='nayk'`,
     * поэтому при включённом флаге fuzzy найдёт товар.
     */
    private Product $shoes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->naikBrand = Brand::create(['name' => 'Найк', 'slug' => 'naik']);
        $this->shoes = Product::factory()->create([
            'name' => 'Кроссовки беговые',
            'brand_id' => $this->naikBrand->id,
        ]);
    }

    private function enableFuzzy(): void
    {
        config([
            'search-cabinet.fuzzy_products' => true,
            'scout.driver' => 'collection',
        ]);
    }

    private function searchCart(string $query): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/carts/search-products?query='.urlencode($query));
        $response->assertOk();

        return $response->json();
    }

    private function fetchFavoriteIds(string $search, ?User $actor = null): array
    {
        $actor ??= $this->user;
        $response = $this->actingAs($actor)
            ->get('/favorites?search='.urlencode($search));
        $response->assertOk();
        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return array_column($page['props']['favorites']['data'] ?? [], 'id');
    }

    // ---------------- searchProducts (cart autocomplete) ----------------

    #[Test]
    public function cart_search_fuzzy_off_does_not_find_via_translit(): void
    {
        // Флаг выключен по умолчанию; LIKE по 'nayk' не сработает.
        $ids = array_column($this->searchCart('nayk'), 'id');
        $this->assertNotContains($this->shoes->id, $ids);
    }

    #[Test]
    public function cart_search_fuzzy_on_finds_via_translit(): void
    {
        $this->enableFuzzy();

        $ids = array_column($this->searchCart('nayk'), 'id');
        $this->assertContains($this->shoes->id, $ids);
    }

    #[Test]
    public function cart_search_fuzzy_skipped_for_barcode_query(): void
    {
        $this->enableFuzzy();

        // Запрос — 13 цифр (TYPE_BARCODE). Fuzzy не должен срабатывать.
        // У товара нет такого штрихкода → выдача не содержит его.
        $ids = array_column($this->searchCart('4607012345678'), 'id');
        $this->assertNotContains($this->shoes->id, $ids);
    }

    // ---------------- favorites listing ----------------

    #[Test]
    public function favorites_fuzzy_off_does_not_find_via_translit(): void
    {
        Favorite::create(['user_id' => $this->user->id, 'product_id' => $this->shoes->id]);

        $ids = $this->fetchFavoriteIds('nayk');
        $this->assertNotContains($this->shoes->id, $ids);
    }

    #[Test]
    public function favorites_fuzzy_on_finds_via_translit(): void
    {
        $this->enableFuzzy();
        Favorite::create(['user_id' => $this->user->id, 'product_id' => $this->shoes->id]);

        $ids = $this->fetchFavoriteIds('nayk');
        $this->assertContains($this->shoes->id, $ids);
    }

    #[Test]
    public function favorites_fuzzy_on_respects_user_scope(): void
    {
        $this->enableFuzzy();

        $other = User::factory()->create();
        Favorite::create(['user_id' => $other->id, 'product_id' => $this->shoes->id]);
        // У текущего пользователя избранного нет — fuzzy должен отдать пусто,
        // даже если Meilisearch вернёт product_id.
        $ids = $this->fetchFavoriteIds('nayk');
        $this->assertNotContains($this->shoes->id, $ids);
    }

    #[Test]
    public function cart_search_fuzzy_skipped_for_short_query(): void
    {
        $this->enableFuzzy();
        // < 2 символов отсекается ещё в контроллере; fuzzy не запускается.
        $this->assertSame([], $this->searchCart('n'));
    }
}
