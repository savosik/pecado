<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBalance>
 */
class UserBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'currency_id' => \App\Models\Currency::factory(),
            'balance' => $this->faker->randomFloat(2, -1000, 1000),
            'overdue_debt' => $this->faker->randomFloat(2, 0, 500),
        ];
    }
}
