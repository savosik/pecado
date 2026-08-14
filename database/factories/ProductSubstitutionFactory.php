<?php

namespace Database\Factories;

use App\Enums\Substitution\LinkKind;
use App\Enums\Substitution\LinkSource;
use App\Models\Product;
use App\Models\ProductSubstitution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSubstitution>
 */
class ProductSubstitutionFactory extends Factory
{
    protected $model = ProductSubstitution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_product_id' => Product::factory(),
            'to_product_id' => Product::factory(),
            'kind' => LinkKind::EQUIVALENT,
            'source' => LinkSource::MANUAL,
            'score' => fake()->numberBetween(40, 95),
            'note' => 'Полный аналог по назначению',
            'confirmed_at' => now(),
        ];
    }

    public function awaitingReview(LinkSource $source = LinkSource::AI): static
    {
        return $this->state(fn () => [
            'source' => $source,
            'confirmed_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'confirmed_at' => null,
            'rejected_at' => now(),
        ]);
    }
}
