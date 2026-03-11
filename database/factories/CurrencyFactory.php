<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Currency>
 */
class CurrencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->currencyCode(),
            'code' => $this->faker->unique()->currencyCode(),
            'symbol' => $this->faker->currencyCode(),
            'official_rate' => null,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 1.0,
            'is_base' => false,
            'exchange_rate_date' => null,
        ];
    }
}
