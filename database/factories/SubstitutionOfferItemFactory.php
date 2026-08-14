<?php

namespace Database\Factories;

use App\Enums\Substitution\CandidateKind;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubstitutionOfferItem>
 */
class SubstitutionOfferItemFactory extends Factory
{
    protected $model = SubstitutionOfferItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => SubstitutionOffer::factory(),
            'source_order_item_id' => OrderItem::factory(),
            'product_id' => Product::factory(),
            'product_defect_id' => null,
            'kind' => CandidateKind::MANUAL,
            'reason' => 'Аналог по назначению и цене',
            'price_snapshot' => fake()->randomFloat(2, 100, 5000),
            'suggested_quantity' => fake()->numberBetween(1, 10),
        ];
    }

    /**
     * Кандидат-партия уценки вместо обычного товара.
     */
    public function defect(?ProductDefect $defect = null): static
    {
        return $this->state(fn () => [
            'product_id' => null,
            'product_defect_id' => $defect?->id ?? ProductDefect::factory(),
            'kind' => CandidateKind::DEFECT_SAME,
            'reason' => 'Идентичный товар, уценка: вмята упаковка',
        ]);
    }

    public function removedByManager(): static
    {
        return $this->state(fn () => ['removed_by_manager_at' => now()]);
    }

    public function chosen(?int $quantity = null): static
    {
        return $this->state(fn (array $attributes) => [
            'chosen' => true,
            'chosen_quantity' => $quantity ?? $attributes['suggested_quantity'] ?? 1,
        ]);
    }
}
