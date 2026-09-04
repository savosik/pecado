<?php

namespace Tests\Unit\Enums;

use App\Enums\CatalogSort;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogSortTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Запрос каталога в том виде, в каком его собирают контроллеры: с
     * подзапросами остатков. Сортировки `default` и `newest` раскладывают
     * выдачу по полкам наличия и без этих алиасов не соберутся.
     *
     * Складов в юнит-тесте нет, поэтому обе полки нулевые и порядок внутри
     * полки виден в чистом виде.
     *
     * @return Builder<Product>
     */
    private function catalogQuery(): Builder
    {
        $query = Product::query()->select('products.*');
        app(StockService::class)->applyStockSubselects($query, null);

        return $query;
    }

    /**
     * Товар с проставленным баллом витрины.
     *
     * Балл вне `$fillable` — его пишет только `catalog:rebuild-sort-scores`
     * запросом, поэтому и тест кладёт его тем же способом.
     */
    private function scoredProduct(float $score, float $price = 100): Product
    {
        $product = Product::factory()->create(['base_price' => $price]);
        DB::table('products')->where('id', $product->id)->update(['sort_score' => $score]);

        return $product;
    }

    // ─── apply() ────────────────────────────────────────────

    public function test_newest_sorts_by_created_at_desc(): void
    {
        $old = Product::factory()->create(['created_at' => now()->subDays(2)]);
        $new = Product::factory()->create(['created_at' => now()]);

        $result = CatalogSort::Newest->apply($this->catalogQuery())->pluck('id');

        $this->assertEquals($new->id, $result->first());
        $this->assertEquals($old->id, $result->last());
    }

    public function test_default_sorts_by_sort_score_desc(): void
    {
        // Товар с высоким баллом заводим первым: вторичный порядок — id desc,
        // и без сортировки по баллу он оказался бы последним.
        $high = $this->scoredProduct(900);
        $low = $this->scoredProduct(10);

        $result = CatalogSort::Default->apply($this->catalogQuery())->pluck('id');

        $this->assertEquals($high->id, $result->first());
        $this->assertEquals($low->id, $result->last());
    }

    public function test_default_puts_products_without_price_below(): void
    {
        // Балл выше, но цены нет — купить нельзя, значит и наверху делать нечего.
        $withPrice = $this->scoredProduct(1, 100);
        $noPrice = $this->scoredProduct(900, 0);

        $result = CatalogSort::Default->apply($this->catalogQuery())->pluck('id');

        $this->assertEquals($withPrice->id, $result->first());
        $this->assertEquals($noPrice->id, $result->last());
    }

    public function test_price_asc_sorts_cheapest_first(): void
    {
        $expensive = Product::factory()->create(['base_price' => 1000]);
        $cheap = Product::factory()->create(['base_price' => 100]);

        $result = CatalogSort::PriceAsc->apply(Product::query())->pluck('id');

        $this->assertEquals($cheap->id, $result->first());
        $this->assertEquals($expensive->id, $result->last());
    }

    public function test_price_desc_sorts_expensive_first(): void
    {
        $cheap = Product::factory()->create(['base_price' => 100]);
        $expensive = Product::factory()->create(['base_price' => 1000]);

        $result = CatalogSort::PriceDesc->apply(Product::query())->pluck('id');

        $this->assertEquals($expensive->id, $result->first());
        $this->assertEquals($cheap->id, $result->last());
    }

    public function test_name_asc_sorts_alphabetically(): void
    {
        $b = Product::factory()->create(['name' => 'Бельё']);
        $a = Product::factory()->create(['name' => 'Аксессуар']);

        $result = CatalogSort::NameAsc->apply(Product::query())->pluck('id');

        $this->assertEquals($a->id, $result->first());
        $this->assertEquals($b->id, $result->last());
    }

    public function test_name_desc_sorts_reverse_alphabetically(): void
    {
        $a = Product::factory()->create(['name' => 'Аксессуар']);
        $b = Product::factory()->create(['name' => 'Бельё']);

        $result = CatalogSort::NameDesc->apply(Product::query())->pluck('id');

        $this->assertEquals($b->id, $result->first());
        $this->assertEquals($a->id, $result->last());
    }

    public function test_apply_clears_existing_order(): void
    {
        Product::factory()->create(['base_price' => 500, 'created_at' => now()->subDay()]);
        Product::factory()->create(['base_price' => 100, 'created_at' => now()]);

        // Сначала сортируем по цене, потом перебиваем на новинки
        $query = $this->catalogQuery()->orderBy('base_price');
        $result = CatalogSort::Newest->apply($query)->pluck('id');

        // Самый новый должен быть первым (не самый дешёвый)
        $newest = Product::query()->orderByDesc('created_at')->first();
        $this->assertEquals($newest->id, $result->first());
    }

    // ─── label() ────────────────────────────────────────────

    public function test_label_returns_russian_string(): void
    {
        foreach (CatalogSort::cases() as $case) {
            $label = $case->label();
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function test_label_values_are_correct(): void
    {
        $this->assertEquals('По умолчанию', CatalogSort::Default->label());
        $this->assertEquals('Новинки', CatalogSort::Newest->label());
        $this->assertEquals('Сначала дешёвые', CatalogSort::PriceAsc->label());
        $this->assertEquals('Сначала дорогие', CatalogSort::PriceDesc->label());
        $this->assertEquals('По имени А–Я', CatalogSort::NameAsc->label());
        $this->assertEquals('По имени Я–А', CatalogSort::NameDesc->label());
        $this->assertEquals('Артикул А–Я', CatalogSort::ArticleAsc->label());
        $this->assertEquals('Артикул Я–А', CatalogSort::ArticleDesc->label());
    }

    // ─── options() ──────────────────────────────────────────

    public function test_options_returns_correct_structure(): void
    {
        $options = CatalogSort::options();

        $this->assertCount(8, $options);

        $this->assertSame('default', $options[0]['value'], 'Витрина по умолчанию должна быть первым пунктом списка');

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertNotNull(CatalogSort::tryFrom($option['value']));
        }
    }

    // ─── tryFrom ────────────────────────────────────────────

    public function test_try_from_returns_enum_for_valid_value(): void
    {
        $this->assertSame(CatalogSort::Default, CatalogSort::tryFrom('default'));
        $this->assertSame(CatalogSort::Newest, CatalogSort::tryFrom('newest'));
        $this->assertSame(CatalogSort::PriceAsc, CatalogSort::tryFrom('price_asc'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(CatalogSort::tryFrom('invalid'));
    }
}
