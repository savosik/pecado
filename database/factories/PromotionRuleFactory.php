<?php

namespace Database\Factories;

use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Product;
use App\Models\PromotionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PromotionRule>
 */
class PromotionRuleFactory extends Factory
{
    protected $model = PromotionRule::class;

    /**
     * По умолчанию — выключенное правило в режиме показа:
     * порог по сумме корзины и бесплатная промо-позиция.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => null,
            'name' => 'Правило «'.fake()->words(2, true).'»',
            'is_active' => false,
            'mode' => PromotionRuleMode::INFO,
            'starts_at' => null,
            'ends_at' => null,
            'priority' => 0,
            'stackable' => true,
            'conditions' => $this->amountConditions(150000),
            'rewards' => [$this->reward(['product_id' => Product::factory()->create()->id])],
            'audience' => null,
            'limits' => null,
        ];
    }

    /** Включённое правило без ограничения периода. */
    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    /** Правило в режиме выдачи промо-позиций (волна 2). */
    public function issuing(): static
    {
        return $this->state(fn () => ['mode' => PromotionRuleMode::ISSUE]);
    }

    /**
     * Условие «сумма корзины по выбранным товарам ≥ N рублей».
     *
     * @param  int[]  $productIds  Товары условия; по умолчанию — вся корзина
     */
    public function amountThreshold(float $value = 150000, array $productIds = []): static
    {
        return $this->state(fn () => [
            'conditions' => $this->amountConditions($value, $productIds),
        ]);
    }

    /**
     * Условие «количество выбранных товаров ≥ N штук».
     *
     * @param  int[]  $productIds
     */
    public function quantityThreshold(int $value = 10, array $productIds = []): static
    {
        return $this->state(fn () => [
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => $this->selector($productIds),
                    'aggregate' => PromotionRule::AGGREGATE_QUANTITY,
                    'operator' => '>=',
                    'value' => $value,
                ]],
            ],
        ]);
    }

    /** Награда — бесплатная промо-позиция (цена 0 ₽). */
    public function freeGift(?int $productId = null): static
    {
        return $this->state(fn () => [
            'rewards' => [$this->reward([
                'product_id' => $productId ?? Product::factory()->create()->id,
                'price' => 0,
                'optional' => false,
            ])],
        ]);
    }

    /** Награда — платная промо-позиция, от которой клиент может отказаться. */
    public function paidPromoItem(float $price = 40, ?int $productId = null): static
    {
        return $this->state(fn () => [
            'rewards' => [$this->reward([
                'product_id' => $productId ?? Product::factory()->create()->id,
                'price' => $price,
                'optional' => true,
            ])],
        ]);
    }

    /** Награда — пробник со склада «Москва реклама» (волна 3). */
    public function sampleReward(?int $productId = null, ?int $warehouseId = null): static
    {
        return $this->state(fn () => [
            'rewards' => [$this->reward([
                'product_id' => $productId ?? Product::factory()->create()->id,
                'price' => 0,
                'promo_kind' => PromoKind::SAMPLE->value,
                'warehouse_id' => $warehouseId,
                'optional' => false,
            ])],
        ]);
    }

    /**
     * Награда, выдаваемая на каждые N рублей / штук, с обязательным потолком.
     *
     * $perValue = null — шаг задают позиции условия (см. perItemSteps()).
     */
    public function perThreshold(?float $perValue = 150000, int $maxMultiplier = 3): static
    {
        return $this->state(function (array $attributes) use ($perValue, $maxMultiplier) {
            $rewards = $attributes['rewards'] ?? [$this->reward()];

            $rewards[0] = array_merge($rewards[0], [
                'multiply' => PromotionRule::MULTIPLY_PER_THRESHOLD,
                'per_value' => $perValue,
                'max_multiplier' => $maxMultiplier,
            ]);

            return ['rewards' => $rewards];
        });
    }

    /**
     * Таблица «артикул → кратность»: по позиции условия на товар, у каждой свой шаг.
     *
     * Так собирается акция, где у пятнадцати SKU пятнадцать разных кратностей,
     * и вклады позиций складываются.
     *
     * @param  array<int, float>  $stepByProductId  product_id → шаг кратности
     */
    public function perItemSteps(array $stepByProductId, string $mode = 'any'): static
    {
        $items = [];

        foreach ($stepByProductId as $productId => $step) {
            $items[] = [
                'selector' => ['products' => [(int) $productId]],
                'aggregate' => PromotionRule::AGGREGATE_QUANTITY,
                'operator' => '>=',
                'value' => $step,
                'per_value' => $step,
            ];
        }

        return $this->state(fn () => ['conditions' => ['mode' => $mode, 'items' => $items]]);
    }

    /**
     * @param  int[]  $productIds
     * @return array<string, mixed>
     */
    private function amountConditions(float $value, array $productIds = []): array
    {
        return [
            'mode' => 'all',
            'items' => [[
                'selector' => $this->selector($productIds),
                'aggregate' => PromotionRule::AGGREGATE_AMOUNT,
                'price_basis' => PromotionRule::PRICE_BASIS_CLIENT_FINAL,
                'operator' => '>=',
                'value' => $value,
            ]],
        ];
    }

    /**
     * @param  int[]  $productIds
     * @return array<string, mixed>
     */
    private function selector(array $productIds): array
    {
        return $productIds === []
            ? ['whole_cart' => true]
            : ['products' => array_values($productIds)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reward(array $overrides = []): array
    {
        return $overrides + [
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
        ];
    }
}
