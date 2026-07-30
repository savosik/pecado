<?php

namespace Tests\Feature\Promotion;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Services\Promotion\PromotionRuleDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Человекочитаемое описание правила: одна формулировка на список,
 * предпросмотр и (позже) корзину с лендингом.
 */
class PromotionRuleDescriberTest extends TestCase
{
    use RefreshDatabase;

    private PromotionRuleDescriber $describer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->describer = app(PromotionRuleDescriber::class);
    }

    /**
     * @param  array<string, mixed>  $selector
     */
    private function rule(array $selector, string $aggregate = 'amount', float $value = 150000): PromotionRule
    {
        return PromotionRule::factory()->make([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => $selector,
                    'aggregate' => $aggregate,
                    'operator' => '>=',
                    'value' => $value,
                ]],
            ],
        ]);
    }

    #[Test]
    public function amount_condition_by_products_is_described_in_russian(): void
    {
        $product = Product::factory()->create(['name' => 'Lovense Lush 4']);
        $rule = $this->rule(['products' => [$product->id]]);

        $this->assertSame(
            'Сумма товаров «Lovense Lush 4» ≥ 150 000 ₽',
            $this->describer->conditionSummary($rule),
        );
    }

    #[Test]
    public function quantity_condition_uses_pieces(): void
    {
        $brand = Brand::factory()->create(['name' => 'Lovense']);
        $rule = $this->rule(['brands' => [$brand->id]], 'quantity', 10);

        $this->assertSame(
            'Количество брендов «Lovense» ≥ 10 шт.',
            $this->describer->conditionSummary($rule),
        );
    }

    #[Test]
    public function categories_with_descendants_are_marked(): void
    {
        $category = Category::factory()->create(['name' => 'Вибраторы']);
        $rule = $this->rule([
            'categories' => [$category->id],
            'with_descendants' => true,
        ], 'quantity', 5);

        $this->assertStringContainsString('категорий «Вибраторы» (с подкатегориями)', $this->describer->conditionSummary($rule));
    }

    #[Test]
    public function whole_cart_selector_is_described(): void
    {
        $rule = $this->rule(['whole_cart' => true]);

        $this->assertSame('Сумма всей корзины ≥ 150 000 ₽', $this->describer->conditionSummary($rule));
    }

    #[Test]
    public function several_conditions_are_joined_by_mode(): void
    {
        $product = Product::factory()->create(['name' => 'Товар А']);
        $brand = Brand::factory()->create(['name' => 'Бренд Б']);

        $rule = PromotionRule::factory()->make([
            'conditions' => [
                'mode' => 'any',
                'items' => [
                    ['selector' => ['products' => [$product->id]], 'aggregate' => 'quantity', 'operator' => '>=', 'value' => 2],
                    ['selector' => ['brands' => [$brand->id]], 'aggregate' => 'amount', 'operator' => '>=', 'value' => 5000],
                ],
            ],
        ]);

        $this->assertSame(
            'Количество товаров «Товар А» ≥ 2 шт. ИЛИ Сумма брендов «Бренд Б» ≥ 5 000 ₽',
            $this->describer->conditionSummary($rule),
        );
    }

    #[Test]
    public function reward_is_described_with_price_and_multiplier(): void
    {
        $product = Product::factory()->create(['name' => 'Lush 4']);

        $rule = PromotionRule::factory()->make([
            'rewards' => [[
                'type' => 'fixed',
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 40,
                'promo_kind' => 'accountable',
                'multiply' => 'per_threshold',
                'max_multiplier' => 3,
            ]],
        ]);

        // Шаг в подписи не упоминается: он у каждого условия свой
        $this->assertSame(
            'Lush 4 × 1 за 40 ₽ (не более 3 раз)',
            $this->describer->rewardSummary($rule),
        );
    }

    #[Test]
    public function choice_reward_lists_options(): void
    {
        $first = Product::factory()->create(['name' => 'Товар 1']);
        $second = Product::factory()->create(['name' => 'Товар 2']);

        $rule = PromotionRule::factory()->make([
            'rewards' => [[
                'type' => 'choice',
                'choices' => [$first->id, $second->id],
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => 'accountable',
                'multiply' => 'once',
            ]],
        ]);

        $this->assertSame(
            'на выбор: «Товар 1», «Товар 2» × 1 за 0 ₽',
            $this->describer->rewardSummary($rule),
        );
    }

    #[Test]
    public function condition_step_is_mentioned_in_the_line(): void
    {
        $product = Product::factory()->create(['name' => 'Lovense Ferri']);

        $rule = PromotionRule::factory()->make([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => ['products' => [$product->id]],
                    'aggregate' => 'quantity',
                    'operator' => '>=',
                    'value' => 4,
                    'per_value' => 4,
                ]],
            ],
        ]);

        $this->assertSame(
            'Количество товаров «Lovense Ferri» ≥ 4 шт. (за каждые 4 шт.)',
            $this->describer->conditionSummary($rule),
        );
    }

    /**
     * Пятнадцать позиций в ячейку таблицы не влезают — вместо перечисления сводка.
     */
    #[Test]
    public function long_condition_list_is_collapsed_into_a_summary(): void
    {
        $products = Product::factory()->count(15)->create();

        $rule = PromotionRule::factory()->make([
            'conditions' => [
                'mode' => 'any',
                'items' => $products->values()->map(fn (Product $product, int $index) => [
                    'selector' => ['products' => [$product->id]],
                    'aggregate' => 'quantity',
                    'operator' => '>=',
                    'value' => $index + 1,
                    'per_value' => $index + 1,
                ])->all(),
            ],
        ]);

        $this->assertSame(
            '15 условий (достаточно любого), количество 1–15 шт., кратность 1–15 шт.',
            $this->describer->conditionSummary($rule),
        );
    }

    #[Test]
    public function status_accounts_for_period(): void
    {
        $disabled = PromotionRule::factory()->make(['is_active' => false]);
        $scheduled = PromotionRule::factory()->make(['is_active' => true, 'starts_at' => now()->addDay()]);
        $finished = PromotionRule::factory()->make(['is_active' => true, 'ends_at' => now()->subDay()]);
        $active = PromotionRule::factory()->make(['is_active' => true]);

        $this->assertSame('Выключено', $this->describer->status($disabled)['label']);
        $this->assertSame('Не начата', $this->describer->status($scheduled)['label']);
        $this->assertSame('Завершена', $this->describer->status($finished)['label']);
        $this->assertSame('Активно', $this->describer->status($active)['label']);
    }

    #[Test]
    public function period_is_formatted_for_humans(): void
    {
        $both = PromotionRule::factory()->make([
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-31 23:59:00',
        ]);

        $this->assertSame('с 01.08.2026 по 31.08.2026', $this->describer->period($both));
        $this->assertSame('—', $this->describer->period(PromotionRule::factory()->make()));
    }
}
