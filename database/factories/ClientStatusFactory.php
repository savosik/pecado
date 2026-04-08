<?php

namespace Database\Factories;

use App\Models\ClientStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientStatusFactory extends Factory
{
    protected $model = ClientStatus::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Silver', 'Gold', 'Diamond', 'Individual']),
            'description' => fake()->sentence(),
            'amount_from' => fake()->randomFloat(2, 0, 100000),
            'external_id' => fake()->unique()->slug(1),
        ];
    }
}
