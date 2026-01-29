<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'base_price' => $this->faker->randomFloat(2, 10, 500),
            'external_id' => $this->faker->uuid(),
            'is_new' => $this->faker->boolean(20),
            'is_bestseller' => $this->faker->boolean(20),
        ];
    }
}
