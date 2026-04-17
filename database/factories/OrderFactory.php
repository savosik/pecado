<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'number' => 'ORD-'.now()->format('Y').'-'.str_pad($this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id' => \App\Models\User::factory(),
            'company_id' => \App\Models\Company::factory(),
            'delivery_address' => fake()->address(),
            'status' => \App\Enums\OrderStatus::PENDING,
            'total_amount' => $this->faker->randomFloat(2, 100, 1000),
            'exchange_rate' => 1.0,
            'rate_coefficient' => 1.0,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::ORDER,
        ];
    }
}
