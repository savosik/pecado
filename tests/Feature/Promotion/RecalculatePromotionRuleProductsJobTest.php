<?php

namespace Tests\Feature\Promotion;

use App\Jobs\RecalculatePromotionRuleProductsJob;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ErpPromotion;
use App\Models\Product;
use App\Models\PromotionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Материализация товаров-участников правила акции в promotion_rule_product.
 */
class RecalculatePromotionRuleProductsJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $selector
     */
    private function ruleWithSelector(array $selector, ?int $rewardProductId = null): PromotionRule
    {
        return PromotionRule::factory()->create([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => $selector,
                    'aggregate' => 'quantity',
                    'operator' => '>=',
                    'value' => 3,
                ]],
            ],
            'rewards' => [[
                'type' => 'fixed',
                'product_id' => $rewardProductId ?? Product::factory()->create()->id,
                'choices' => null,
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => 'accountable',
                'warehouse_id' => null,
                'multiply' => 'once',
                'max_multiplier' => 1,
                'optional' => false,
            ]],
        ]);
    }

    /**
     * @return int[]
     */
    private function participants(PromotionRule $rule, string $role): array
    {
        return DB::table('promotion_rule_product')
            ->where('promotion_rule_id', $rule->id)
            ->where('role', $role)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public function test_explicit_products_are_materialized(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $other = Product::factory()->create();

        $rule = $this->ruleWithSelector(['products' => [$first->id, $second->id]]);

        $participants = $this->participants($rule, PromotionRule::ROLE_CONDITION);

        $this->assertSame(collect([$first->id, $second->id])->sort()->values()->all(), $participants);
        $this->assertNotContains($other->id, $participants);
    }

    public function test_categories_are_expanded_with_descendants(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create();
        $child->appendToNode($parent)->save();

        $parentProduct = Product::factory()->create(['category_id' => $parent->id]);
        $childProduct = Product::factory()->create(['category_id' => $child->id]);
        $outsideProduct = Product::factory()->create();

        $rule = $this->ruleWithSelector([
            'categories' => [$parent->id],
            'with_descendants' => true,
        ]);

        $participants = $this->participants($rule, PromotionRule::ROLE_CONDITION);

        $this->assertContains($parentProduct->id, $participants);
        $this->assertContains($childProduct->id, $participants);
        $this->assertNotContains($outsideProduct->id, $participants);
    }

    public function test_categories_without_descendants_do_not_leak_children(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create();
        $child->appendToNode($parent)->save();

        $parentProduct = Product::factory()->create(['category_id' => $parent->id]);
        $childProduct = Product::factory()->create(['category_id' => $child->id]);

        $rule = $this->ruleWithSelector([
            'categories' => [$parent->id],
            'with_descendants' => false,
        ]);

        $participants = $this->participants($rule, PromotionRule::ROLE_CONDITION);

        $this->assertContains($parentProduct->id, $participants);
        $this->assertNotContains($childProduct->id, $participants);
    }

    public function test_brands_are_materialized(): void
    {
        $brand = Brand::factory()->create();
        $branded = Product::factory()->create(['brand_id' => $brand->id]);
        Product::factory()->create();

        $rule = $this->ruleWithSelector(['brands' => [$brand->id]]);

        $this->assertSame([$branded->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_tags_are_materialized(): void
    {
        $tagged = Product::factory()->create();
        $tagged->attachTag('lovense');
        Product::factory()->create();

        $rule = $this->ruleWithSelector(['tags' => ['lovense']]);

        $this->assertSame([$tagged->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_erp_promotions_are_materialized(): void
    {
        $erpPromotion = ErpPromotion::create([
            'uuid' => (string) Str::uuid(),
            'type' => ErpPromotion::TYPE_LIQUIDATION,
        ]);

        $liquidated = Product::factory()->create();
        $erpPromotion->products()->attach($liquidated->id);
        Product::factory()->create();

        $rule = $this->ruleWithSelector(['erp_promotions' => ['liquidation']]);

        $this->assertSame([$liquidated->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_selector_fields_are_combined_with_or(): void
    {
        $brand = Brand::factory()->create();
        $branded = Product::factory()->create(['brand_id' => $brand->id]);
        $explicit = Product::factory()->create();
        Product::factory()->create();

        $rule = $this->ruleWithSelector([
            'products' => [$explicit->id],
            'brands' => [$brand->id],
        ]);

        $this->assertSame(
            collect([$branded->id, $explicit->id])->sort()->values()->all(),
            $this->participants($rule, PromotionRule::ROLE_CONDITION),
        );
    }

    public function test_whole_cart_selector_does_not_materialize_catalog(): void
    {
        Product::factory()->count(3)->create();

        $rule = $this->ruleWithSelector(['whole_cart' => true]);

        $this->assertSame([], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_reward_products_get_reward_role(): void
    {
        $rewardProduct = Product::factory()->create();
        $conditionProduct = Product::factory()->create();

        $rule = $this->ruleWithSelector(['products' => [$conditionProduct->id]], $rewardProduct->id);

        $this->assertSame([$conditionProduct->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
        $this->assertSame([$rewardProduct->id], $this->participants($rule, PromotionRule::ROLE_REWARD));
    }

    public function test_choice_products_are_materialized_as_rewards(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $conditionProduct = Product::factory()->create();

        $rule = PromotionRule::factory()->create([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => ['products' => [$conditionProduct->id]],
                    'aggregate' => 'quantity',
                    'operator' => '>=',
                    'value' => 3,
                ]],
            ],
            'rewards' => [[
                'type' => 'choice',
                'product_id' => null,
                'choices' => [$first->id, $second->id],
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => 'accountable',
                'warehouse_id' => null,
                'multiply' => 'once',
                'max_multiplier' => 1,
                'optional' => false,
            ]],
        ]);

        $this->assertSame(
            collect([$first->id, $second->id])->sort()->values()->all(),
            $this->participants($rule, PromotionRule::ROLE_REWARD),
        );
    }

    public function test_rerun_does_not_produce_duplicates(): void
    {
        $product = Product::factory()->create();
        $rule = $this->ruleWithSelector(['products' => [$product->id]]);

        $before = DB::table('promotion_rule_product')->where('promotion_rule_id', $rule->id)->count();

        dispatch_sync(new RecalculatePromotionRuleProductsJob($rule->id));
        dispatch_sync(new RecalculatePromotionRuleProductsJob($rule->id));

        $after = DB::table('promotion_rule_product')->where('promotion_rule_id', $rule->id)->count();

        $this->assertSame($before, $after);
    }

    public function test_changing_selector_rebuilds_participants(): void
    {
        $old = Product::factory()->create();
        $new = Product::factory()->create();

        $rule = $this->ruleWithSelector(['products' => [$old->id]]);
        $this->assertSame([$old->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));

        $conditions = $rule->conditions;
        $conditions['items'][0]['selector'] = ['products' => [$new->id]];
        $rule->update(['conditions' => $conditions]);

        $this->assertSame([$new->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_soft_deleted_rule_loses_participants(): void
    {
        $product = Product::factory()->create();
        $rule = $this->ruleWithSelector(['products' => [$product->id]]);

        $rule->delete();

        $this->assertSame(0, DB::table('promotion_rule_product')->where('promotion_rule_id', $rule->id)->count());

        $rule->restore();

        $this->assertSame([$product->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_hidden_products_are_still_participants(): void
    {
        $hidden = Product::factory()->create(['hidden' => true]);

        $rule = $this->ruleWithSelector(['products' => [$hidden->id]]);

        $this->assertSame([$hidden->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }

    public function test_command_rebuilds_all_rules(): void
    {
        $product = Product::factory()->create();
        $rule = $this->ruleWithSelector(['products' => [$product->id]]);

        DB::table('promotion_rule_product')->where('promotion_rule_id', $rule->id)->delete();

        $this->artisan('promo:rebuild-rule-products')->assertSuccessful();

        $this->assertSame([$product->id], $this->participants($rule, PromotionRule::ROLE_CONDITION));
    }
}
