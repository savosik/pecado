<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'external_id' => $this->faker->uuid(),
            'short_description' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'meta_title' => $this->faker->words(3, true),
            'meta_description' => $this->faker->sentence(),
        ];
    }
}
