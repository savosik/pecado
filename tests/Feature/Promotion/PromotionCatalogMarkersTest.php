<?php

namespace Tests\Feature\Promotion;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\User;
use App\Services\Product\ProductQueryService;
use App\Services\Promotion\ActivePromotionRuleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Маркеры участия в акции в каталоге и карточке товара (карточка promo-04).
 */
class PromotionCatalogMarkersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ActivePromotionRuleCache::class)->flush();
    }

    /**
     * @param  Product[]|\Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function rows($products): array
    {
        return collect($products)->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->all();
    }

    private function ruleWithCondition(Product $product, array $attributes = []): PromotionRule
    {
        return PromotionRule::factory()
            ->active()
            ->amountThreshold(1000, [$product->id])
            ->create($attributes);
    }

    // ────────────────────────────────────────────
    // Источники флага
    // ────────────────────────────────────────────

    #[Test]
    public function content_promotion_marks_product(): void
    {
        $product = Product::factory()->create();
        $promotion = Promotion::factory()->create(['is_active' => true, 'name' => 'Летняя распродажа']);
        $promotion->products()->attach($product->id);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]));

        $this->assertTrue($enriched[0]['has_promotion']);
        $this->assertFalse($enriched[0]['is_promo_reward']);
        $this->assertSame('Летняя распродажа', $enriched[0]['promotion_name']);
    }

    #[Test]
    public function inactive_content_promotion_does_not_mark_product(): void
    {
        $product = Product::factory()->create();
        $promotion = Promotion::factory()->create(['is_active' => false]);
        $promotion->products()->attach($product->id);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]));

        $this->assertFalse($enriched[0]['has_promotion']);
    }

    #[Test]
    public function rule_condition_marks_product(): void
    {
        $product = Product::factory()->create();
        $this->ruleWithCondition($product, ['name' => 'Правило Lovense']);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]));

        $this->assertTrue($enriched[0]['has_promotion']);
        $this->assertSame('Правило Lovense', $enriched[0]['promotion_name']);
    }

    #[Test]
    public function disabled_rule_does_not_mark_product(): void
    {
        $product = Product::factory()->create();
        PromotionRule::factory()->amountThreshold(1000, [$product->id])->create(); // is_active = false

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]));

        $this->assertFalse($enriched[0]['has_promotion']);
    }

    #[Test]
    public function reward_product_gets_its_own_marker(): void
    {
        $conditionProduct = Product::factory()->create();
        $rewardProduct = Product::factory()->create();

        PromotionRule::factory()
            ->active()
            ->amountThreshold(1000, [$conditionProduct->id])
            ->freeGift($rewardProduct->id)
            ->create();

        $enriched = collect(ProductQueryService::enrichProductsWithPromotions(
            $this->rows([$conditionProduct, $rewardProduct])
        ))->keyBy('id');

        // Участник условия — «купи меня по акции»
        $this->assertTrue($enriched[$conditionProduct->id]['has_promotion']);
        $this->assertFalse($enriched[$conditionProduct->id]['is_promo_reward']);

        // Награда — «это можно получить», но сам он в акции не участвует
        $this->assertTrue($enriched[$rewardProduct->id]['is_promo_reward']);
        $this->assertFalse($enriched[$rewardProduct->id]['has_promotion']);
    }

    #[Test]
    public function two_promotions_leave_tooltip_empty(): void
    {
        $product = Product::factory()->create();

        foreach (['Первая акция', 'Вторая акция'] as $name) {
            $promotion = Promotion::factory()->create(['is_active' => true, 'name' => $name]);
            $promotion->products()->attach($product->id);
        }

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]));

        $this->assertTrue($enriched[0]['has_promotion']);
        // Перечислять несколько названий в подсказке на карточке некуда
        $this->assertNull($enriched[0]['promotion_name']);
    }

    #[Test]
    public function product_in_both_sources_is_marked_once(): void
    {
        $product = Product::factory()->create();
        $promotion = Promotion::factory()->create(['is_active' => true, 'name' => 'Общая акция']);
        $promotion->products()->attach($product->id);
        $this->ruleWithCondition($product, ['name' => 'Общая акция']);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]));

        $this->assertCount(1, $enriched);
        $this->assertTrue($enriched[0]['has_promotion']);
        // Название одно и то же — подсказка остаётся
        $this->assertSame('Общая акция', $enriched[0]['promotion_name']);
    }

    // ────────────────────────────────────────────
    // Регионы
    // ────────────────────────────────────────────

    #[Test]
    public function promotion_of_another_region_does_not_mark_product(): void
    {
        $product = Product::factory()->create();
        $moscow = Region::factory()->create();
        $tyumen = Region::factory()->create();

        $promotion = Promotion::factory()->create(['is_active' => true]);
        $promotion->products()->attach($product->id);
        $promotion->regions()->attach($moscow->id);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]), $tyumen->id);
        $this->assertFalse($enriched[0]['has_promotion']);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]), $moscow->id);
        $this->assertTrue($enriched[0]['has_promotion']);
    }

    #[Test]
    public function rule_of_another_region_does_not_mark_product(): void
    {
        $product = Product::factory()->create();
        $moscow = Region::factory()->create();
        $tyumen = Region::factory()->create();

        $this->ruleWithCondition($product, ['audience' => ['region_ids' => [$moscow->id]]]);

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]), $tyumen->id);
        $this->assertFalse($enriched[0]['has_promotion']);

        app(ActivePromotionRuleCache::class)->flush();

        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]), $moscow->id);
        $this->assertTrue($enriched[0]['has_promotion']);
    }

    #[Test]
    public function promotion_without_regions_is_visible_to_guest(): void
    {
        $product = Product::factory()->create();
        $promotion = Promotion::factory()->create(['is_active' => true]);
        $promotion->products()->attach($product->id);

        // Регион не задан — гость: контент без привязки виден всем
        $enriched = ProductQueryService::enrichProductsWithPromotions($this->rows([$product]), null);

        $this->assertTrue($enriched[0]['has_promotion']);
    }

    // ────────────────────────────────────────────
    // Производительность
    // ────────────────────────────────────────────

    #[Test]
    public function enrichment_costs_one_query_regardless_of_list_size(): void
    {
        $products = Product::factory()->count(30)->create();
        $this->ruleWithCondition($products->first());

        // Прогреваем кэш активных правил — на странице каталога он уже прогрет
        app(ActivePromotionRuleCache::class)->activeAt();

        DB::enableQueryLog();
        DB::flushQueryLog();

        ProductQueryService::enrichProductsWithPromotions($this->rows($products));

        $this->assertCount(1, DB::getQueryLog(), 'Обогащение должно укладываться в один запрос');

        DB::disableQueryLog();
    }

    // ────────────────────────────────────────────
    // Фильтр каталога
    // ────────────────────────────────────────────

    #[Test]
    public function catalog_filter_returns_only_promotion_participants(): void
    {
        $participant = Product::factory()->create();
        $viaRule = Product::factory()->create();
        Product::factory()->create(); // посторонний товар

        $promotion = Promotion::factory()->create(['is_active' => true]);
        $promotion->products()->attach($participant->id);
        $this->ruleWithCondition($viaRule);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/catalog/products?in_promotion=1')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$participant->id, $viaRule->id], $ids);
    }

    #[Test]
    public function catalog_filter_excludes_reward_only_products(): void
    {
        $conditionProduct = Product::factory()->create();
        $rewardProduct = Product::factory()->create();

        PromotionRule::factory()
            ->active()
            ->amountThreshold(1000, [$conditionProduct->id])
            ->freeGift($rewardProduct->id)
            ->create();

        $response = $this->getJson('/api/catalog/products?in_promotion=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        // «Можно получить по акции» — не то же самое, что «участвует в акции»
        $this->assertSame([$conditionProduct->id], $ids);
    }

    #[Test]
    public function catalog_response_carries_promotion_markers(): void
    {
        $product = Product::factory()->create();
        $promotion = Promotion::factory()->create(['is_active' => true, 'name' => 'Акция месяца']);
        $promotion->products()->attach($product->id);

        $this->getJson('/api/catalog/products')
            ->assertOk()
            ->assertJsonPath('data.0.has_promotion', true)
            ->assertJsonPath('data.0.is_promo_reward', false)
            ->assertJsonPath('data.0.promotion_name', 'Акция месяца');
    }
}
