<?php

namespace Tests\Feature\Promotion;

use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Contracts\Promotion\PromoUsageCounterInterface;
use App\Enums\PromoBlockReason;
use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\DTO\AppliedReward;
use App\Services\Promotion\DTO\PromoContext;
use App\Services\Promotion\DTO\PromoContextLine;
use App\Services\Promotion\PromotionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Движок расчёта акций: условия, награды, конфликты, подсказки.
 */
class PromotionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): PromotionEngine
    {
        return app(PromotionEngine::class);
    }

    /**
     * @param  array<string, mixed>  $selector
     * @param  array<string, mixed>  $overrides
     */
    private function makeRule(
        array $selector,
        string $aggregate,
        float $value,
        array $reward,
        array $overrides = [],
        string $operator = '>=',
    ): PromotionRule {
        return PromotionRule::factory()->active()->create(array_merge([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => $selector,
                    'aggregate' => $aggregate,
                    'operator' => $operator,
                    'value' => $value,
                ]],
            ],
            'rewards' => [$this->reward($reward)],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reward(array $overrides = []): array
    {
        return array_merge([
            'type' => PromotionRule::REWARD_TYPE_FIXED,
            'product_id' => null,
            'choices' => null,
            'quantity' => 1,
            'price' => 0,
            'promo_kind' => PromoKind::ACCOUNTABLE->value,
            'warehouse_id' => null,
            'multiply' => PromotionRule::MULTIPLY_ONCE,
            'per_value' => null,
            'max_multiplier' => 1,
            'optional' => false,
        ], $overrides);
    }

    /**
     * @param  array<int, array{0: Product, 1: int, 2?: float|null}>  $lines
     */
    private function context(array $lines, ?User $user = null, array $extra = []): PromoContext
    {
        return PromoContext::fromLines(
            array_map(fn (array $line) => new PromoContextLine(
                productId: $line[0]->id,
                quantity: $line[1],
                unitPrice: $line[2] ?? null,
            ), $lines),
            $user,
            ...$extra,
        );
    }

    // ─── Условия ────────────────────────────────────────────────

    public function test_quantity_threshold_fires(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 3, ['product_id' => $gift->id]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 3]]));

        $this->assertCount(1, $result->applied);
        $this->assertSame($gift->id, $result->applied[0]->productId);
        $this->assertSame(1, $result->applied[0]->quantity);
    }

    public function test_quantity_below_threshold_does_not_fire(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 3, [
            'product_id' => Product::factory()->create()->id,
        ]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 2]]));

        $this->assertSame([], $result->applied);
    }

    public function test_amount_threshold_uses_client_final_price(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-promo-1']);
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        // Индивидуальная цена вдвое ниже базовой: порог 6000 по базовой был бы пройден,
        // по цене клиента — нет
        IndividualPrice::create([
            'partner_id' => $user->id,
            'product_id' => $trigger->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'price' => 500,
        ]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 6000, [
            'product_id' => $gift->id,
        ]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 8]], $user));

        $this->assertSame([], $result->applied, 'Сумма должна считаться по индивидуальной цене (4000), а не по базовой (8000)');

        $result = $this->engine()->evaluate($this->context([[$trigger, 12]], $user));

        $this->assertCount(1, $result->applied, 'При 12 шт по 500 ₽ сумма 6000 ₽ — порог достигнут');
    }

    public function test_condition_mode_all_requires_every_item(): void
    {
        $first = Product::factory()->create(['base_price' => 100]);
        $second = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        PromotionRule::factory()->active()->create([
            'conditions' => [
                'mode' => 'all',
                'items' => [
                    ['selector' => ['products' => [$first->id]], 'aggregate' => 'quantity', 'operator' => '>=', 'value' => 2],
                    ['selector' => ['products' => [$second->id]], 'aggregate' => 'quantity', 'operator' => '>=', 'value' => 2],
                ],
            ],
            'rewards' => [$this->reward(['product_id' => $gift->id])],
        ]);

        $this->assertSame([], $this->engine()->evaluate($this->context([[$first, 2]]))->applied);
        $this->assertCount(1, $this->engine()->evaluate($this->context([[$first, 2], [$second, 2]]))->applied);
    }

    public function test_condition_mode_any_needs_single_item(): void
    {
        $first = Product::factory()->create(['base_price' => 100]);
        $second = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        PromotionRule::factory()->active()->create([
            'conditions' => [
                'mode' => 'any',
                'items' => [
                    ['selector' => ['products' => [$first->id]], 'aggregate' => 'quantity', 'operator' => '>=', 'value' => 2],
                    ['selector' => ['products' => [$second->id]], 'aggregate' => 'quantity', 'operator' => '>=', 'value' => 2],
                ],
            ],
            'rewards' => [$this->reward(['product_id' => $gift->id])],
        ]);

        $this->assertCount(1, $this->engine()->evaluate($this->context([[$first, 2]]))->applied);
    }

    public function test_whole_cart_selector_counts_every_line(): void
    {
        $first = Product::factory()->create(['base_price' => 100]);
        $second = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        $this->makeRule(['whole_cart' => true], PromotionRule::AGGREGATE_AMOUNT, 1000, ['product_id' => $gift->id]);

        $result = $this->engine()->evaluate($this->context([[$first, 5], [$second, 5]]));

        $this->assertCount(1, $result->applied);
    }

    // ─── Кратность ──────────────────────────────────────────────

    public function test_multiply_once_gives_single_reward(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 1000, [
            'product_id' => $gift->id,
            'quantity' => 2,
        ]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 10]]));

        $this->assertSame(2, $result->applied[0]->quantity);
    }

    public function test_per_threshold_multiplies_up_to_cap(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 1000, [
            'product_id' => $gift->id,
            'multiply' => PromotionRule::MULTIPLY_PER_THRESHOLD,
            'per_value' => 1000,
            'max_multiplier' => 3,
        ]);

        $this->assertSame(2, $this->engine()->evaluate($this->context([[$trigger, 2]]))->applied[0]->quantity);
        $this->assertSame(3, $this->engine()->evaluate($this->context([[$trigger, 5]]))->applied[0]->quantity,
            'Потолок кратности обязан ограничить выдачу');
    }

    // ─── Выбор клиента ──────────────────────────────────────────

    public function test_choice_reward_uses_client_selection(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $rule = $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1, [
            'type' => PromotionRule::REWARD_TYPE_CHOICE,
            'product_id' => null,
            'choices' => [$first->id, $second->id],
        ]);

        $withoutSelection = $this->engine()->evaluate($this->context([[$trigger, 1]]));
        $this->assertSame($first->id, $withoutSelection->applied[0]->productId, 'Без выбора берём первый вариант');

        $context = PromoContext::fromLines(
            [new PromoContextLine($trigger->id, 1)],
            null,
            PromotionRule::CHANNEL_SITE,
            [PromoContext::selectionKey($rule->id, 0) => ['product_id' => $second->id]],
        );

        $this->assertSame($second->id, $this->engine()->evaluate($context)->applied[0]->productId);
    }

    public function test_declined_paid_reward_is_marked_declined(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $rule = $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1, [
            'product_id' => $gift->id,
            'price' => 40,
            'optional' => true,
        ]);

        $context = PromoContext::fromLines(
            [new PromoContextLine($trigger->id, 1)],
            null,
            PromotionRule::CHANNEL_SITE,
            [PromoContext::selectionKey($rule->id, 0) => ['declined' => true]],
        );

        $applied = $this->engine()->evaluate($context)->applied[0];

        $this->assertTrue($applied->optional);
        $this->assertTrue($applied->declined);
        $this->assertFalse($applied->isIssuable());
    }

    public function test_free_reward_cannot_be_declined(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $rule = $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1, [
            'product_id' => $gift->id,
            'price' => 0,
            'optional' => true,
        ]);

        $context = PromoContext::fromLines(
            [new PromoContextLine($trigger->id, 1)],
            null,
            PromotionRule::CHANNEL_SITE,
            [PromoContext::selectionKey($rule->id, 0) => ['declined' => true]],
        );

        $applied = $this->engine()->evaluate($context)->applied[0];

        $this->assertFalse($applied->optional);
        $this->assertFalse($applied->declined);
    }

    // ─── Конфликты и приоритеты ─────────────────────────────────

    public function test_non_stackable_rule_wins_alone_by_priority(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $exclusiveGift = Product::factory()->create();
        $otherGift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => $exclusiveGift->id], ['priority' => 10, 'stackable' => false]);
        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => $otherGift->id], ['priority' => 5]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertCount(1, $result->applied);
        $this->assertSame($exclusiveGift->id, $result->applied[0]->productId);
    }

    public function test_non_stackable_rule_loses_when_priority_is_lower(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $stackableGift = Product::factory()->create();
        $exclusiveGift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => $stackableGift->id], ['priority' => 10]);
        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => $exclusiveGift->id], ['priority' => 1, 'stackable' => false]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertCount(1, $result->applied);
        $this->assertSame($stackableGift->id, $result->applied[0]->productId);
    }

    public function test_stackable_rules_are_applied_together_in_priority_order(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $low = Product::factory()->create();
        $high = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => $low->id], ['priority' => 1]);
        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => $high->id], ['priority' => 9]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertSame(
            [$high->id, $low->id],
            array_map(fn (AppliedReward $reward) => $reward->productId, $result->applied),
        );
    }

    // ─── Гейты ──────────────────────────────────────────────────

    public function test_rule_outside_channel_is_blocked(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => Product::factory()->create()->id], ['audience' => ['channels' => ['api']]]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertSame([], $result->applied);
        $this->assertSame(PromoBlockReason::WRONG_CHANNEL, $result->blocked[0]->reason);
    }

    public function test_info_rule_is_blocked_when_issuing_requested(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => Product::factory()->create()->id]);

        $context = PromoContext::fromLines(
            [new PromoContextLine($trigger->id, 1)],
            null,
            PromotionRule::CHANNEL_SITE,
            [],
            PromotionRuleMode::ISSUE,
        );

        $result = $this->engine()->evaluate($context);

        $this->assertSame([], $result->applied);
        $this->assertSame(PromoBlockReason::NOT_ACTIVE_YET, $result->blocked[0]->reason);
    }

    public function test_limits_block_the_rule(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => Product::factory()->create()->id], ['limits' => ['total' => 5, 'per_client_total' => null]]);

        $this->app->bind(PromoUsageCounterInterface::class, fn () => new class implements PromoUsageCounterInterface
        {
            public function totalUsage(int $ruleId): int
            {
                return 5;
            }

            public function clientUsage(int $ruleId, ?int $userId): int
            {
                return 0;
            }
        });

        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertSame([], $result->applied);
        $this->assertSame(PromoBlockReason::TOTAL_LIMIT, $result->blocked[0]->reason);
    }

    public function test_missing_stock_blocks_the_reward(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1, ['product_id' => $gift->id]);

        $this->app->bind(PromoStockCheckerInterface::class, fn () => new class implements PromoStockCheckerInterface
        {
            public function isAvailable(int $productId, ?int $warehouseId, int $quantity, ?int $userId = null): bool
            {
                return false;
            }
        });

        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertSame([], $result->applied);
        $this->assertSame(PromoBlockReason::OUT_OF_STOCK, $result->blocked[0]->reason);
        $this->assertSame($gift->id, $result->blocked[0]->productId);
    }

    public function test_audience_by_user_excludes_guests_and_others(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $allowed = User::factory()->create();
        $other = User::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
            ['product_id' => Product::factory()->create()->id], ['audience' => ['user_ids' => [$allowed->id]]]);

        $this->assertCount(1, $this->engine()->evaluate($this->context([[$trigger, 1]], $allowed))->applied);
        $this->assertSame([], $this->engine()->evaluate($this->context([[$trigger, 1]], $other))->applied);
        $this->assertSame([], $this->engine()->evaluate($this->context([[$trigger, 1]]))->applied);
    }

    // ─── Промо-строки и валюта ──────────────────────────────────

    public function test_promo_lines_are_excluded_from_aggregates(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $promoProduct = Product::factory()->create(['base_price' => 1000]);

        $this->makeRule(['whole_cart' => true], PromotionRule::AGGREGATE_AMOUNT, 1040, [
            'product_id' => Product::factory()->create()->id,
        ]);

        $context = PromoContext::fromLines([
            new PromoContextLine($trigger->id, 1, 1000.0),
            // платная промо-позиция за 40 ₽ не должна тянуть следующее правило
            new PromoContextLine($promoProduct->id, 1, 40.0, isPromo: true),
        ]);

        $this->assertSame([], $this->engine()->evaluate($context)->applied);
    }

    public function test_threshold_in_rubles_is_compared_with_foreign_currency_cart(): void
    {
        Currency::query()->update(['is_base' => false]);
        $rub = Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);
        $byn = Currency::factory()->create(['code' => 'BYN', 'is_base' => false, 'exchange_rate' => 30]);

        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        // Порог 6000 ₽ = 200 BYN по курсу 30
        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 6000, ['product_id' => $gift->id]);

        $below = PromoContext::fromLines([new PromoContextLine($trigger->id, 1, 100.0)], null,
            PromotionRule::CHANNEL_SITE, [], null, $byn);
        $above = PromoContext::fromLines([new PromoContextLine($trigger->id, 1, 250.0)], null,
            PromotionRule::CHANNEL_SITE, [], null, $byn);

        $this->assertSame([], $this->engine()->evaluate($below)->applied, '100 BYN = 3000 ₽ — порога нет');
        $this->assertCount(1, $this->engine()->evaluate($above)->applied, '250 BYN = 7500 ₽ — порог пройден');

        $this->assertTrue($rub->is_base);
    }

    // ─── Подсказки ──────────────────────────────────────────────

    public function test_near_miss_is_reported_for_touched_rule(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 10000, ['product_id' => $gift->id]);

        $result = $this->engine()->evaluate($this->context([[$trigger, 8]]));

        $this->assertCount(1, $result->nearMiss);
        $this->assertSame(2000.0, $result->nearMiss[0]->remaining());
        $this->assertSame(0.8, $result->nearMiss[0]->progress());
        $this->assertSame([$gift->id], $result->nearMiss[0]->rewardProductIds);
    }

    public function test_no_near_miss_when_cart_has_no_rule_products(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $unrelated = Product::factory()->create(['base_price' => 1000]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 10000, [
            'product_id' => Product::factory()->create()->id,
        ]);

        $result = $this->engine()->evaluate($this->context([[$unrelated, 1]]));

        $this->assertSame([], $result->nearMiss);
    }

    public function test_no_near_miss_below_minimum_progress(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 10000, [
            'product_id' => Product::factory()->create()->id,
        ]);

        // 100 ₽ из 10 000 ₽ — 1 %, ниже порога показа
        $result = $this->engine()->evaluate($this->context([[$trigger, 1]]));

        $this->assertSame([], $result->nearMiss);
    }

    // ─── Общие свойства ─────────────────────────────────────────

    public function test_empty_cart_does_not_touch_database(): void
    {
        $this->makeRule(['whole_cart' => true], PromotionRule::AGGREGATE_QUANTITY, 1, [
            'product_id' => Product::factory()->create()->id,
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $result = $this->engine()->evaluate(PromoContext::fromLines([]));

        $this->assertSame(0, $queries);
        $this->assertSame([], $result->applied);
    }

    public function test_evaluation_is_deterministic(): void
    {
        $trigger = Product::factory()->create(['base_price' => 1000]);

        foreach (range(1, 5) as $i) {
            $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1,
                ['product_id' => Product::factory()->create()->id], ['priority' => 3]);
        }

        $context = $this->context([[$trigger, 5]]);
        $first = $this->engine()->evaluate($context)->toArray();

        foreach (range(1, 9) as $i) {
            $this->assertSame($first, $this->engine()->evaluate($context)->toArray(), "Вызов {$i} дал другой результат");
        }
    }

    public function test_query_count_does_not_grow_with_cart_size(): void
    {
        $products = Product::factory()->count(200)->create(['base_price' => 100]);

        // 20 правил: половина с одним условием, половина с двумя — чтобы не мерить
        // самый выгодный случай
        foreach (range(0, 19) as $index) {
            $selectorProducts = $products->slice($index, 5)->pluck('id')->all();

            if ($index % 2 === 0) {
                $this->makeRule(['products' => $selectorProducts], PromotionRule::AGGREGATE_QUANTITY, 3, [
                    'product_id' => Product::factory()->create()->id,
                ]);

                continue;
            }

            PromotionRule::factory()->active()->create([
                'conditions' => [
                    'mode' => 'all',
                    'items' => [
                        ['selector' => ['products' => $selectorProducts], 'aggregate' => 'quantity', 'operator' => '>=', 'value' => 3],
                        ['selector' => ['whole_cart' => true], 'aggregate' => 'amount', 'operator' => '>=', 'value' => 1000],
                    ],
                ],
                'rewards' => [$this->reward(['product_id' => Product::factory()->create()->id])],
            ]);
        }

        $smallContext = $this->context($products->take(5)->map(fn (Product $p) => [$p, 2])->all());
        $largeContext = $this->context($products->map(fn (Product $p) => [$p, 2])->all());

        // Прогреваем кэш правил, чтобы мерить одинаковые условия
        $this->engine()->evaluate($smallContext);

        $small = $this->countQueries(fn () => $this->engine()->evaluate($smallContext));
        $large = $this->countQueries(fn () => $this->engine()->evaluate($largeContext));

        $this->assertSame($small, $large, 'Число запросов не должно зависеть от размера корзины');
        $this->assertLessThanOrEqual(12, $large, 'Слишком много запросов на один расчёт');
    }

    public function test_from_cart_and_from_lines_agree(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-promo-agree']);
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $gift = Product::factory()->create();

        $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_AMOUNT, 3000, ['product_id' => $gift->id]);

        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $cart->items()->create([
            'product_id' => $trigger->id,
            'quantity' => 4,
            'price' => 1000,
            'item_type' => 'instock',
        ]);

        $fromCart = $this->engine()->evaluate(PromoContext::fromCart($cart->fresh(), $user));
        $fromLines = $this->engine()->evaluate($this->context([[$trigger, 4]], $user));

        $this->assertSame($fromLines->toArray(), $fromCart->toArray());
        $this->assertCount(1, $fromCart->applied);
    }

    public function test_from_cart_picks_up_client_selection(): void
    {
        $user = User::factory()->create();
        $trigger = Product::factory()->create(['base_price' => 1000]);
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $rule = $this->makeRule(['products' => [$trigger->id]], PromotionRule::AGGREGATE_QUANTITY, 1, [
            'type' => PromotionRule::REWARD_TYPE_CHOICE,
            'product_id' => null,
            'choices' => [$first->id, $second->id],
        ]);

        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $trigger->id, 'quantity' => 1, 'price' => 1000, 'item_type' => 'instock']);
        $cart->promotionSelections()->create([
            'promotion_rule_id' => $rule->id,
            'reward_index' => 0,
            'product_id' => $second->id,
        ]);

        $result = $this->engine()->evaluate(PromoContext::fromCart($cart->fresh(), $user));

        $this->assertSame($second->id, $result->applied[0]->productId);
    }

    private function countQueries(callable $callback): int
    {
        $queries = 0;

        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $callback();

        return $queries;
    }
}
