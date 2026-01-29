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
            'user_id' => \App\Models\User::factory(),
            'company_id' => \App\Models\Company::factory(),
            'delivery_address_id' => \App\Models\DeliveryAddress::factory(),
            'status' => \App\Enums\OrderStatus::PENDING,
            'total_amount' => $this->faker->randomFloat(2, 100, 1000),
            'exchange_rate' => 1.0,
            'correction_factor' => 1.0,
            'currency_code' => 'RUB',
            'type' => \App\Enums\OrderType::STANDARD,
        ];
    }
}
