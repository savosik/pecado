<?php

namespace Database\Factories;

use App\Enums\ReturnStatus;
use App\Models\ProductReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductReturn>
 */
class ProductReturnFactory extends Factory
{
    protected $model = ProductReturn::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'status' => ReturnStatus::PENDING,
            'comment' => $this->faker->optional()->sentence(),
            'admin_comment' => null,
            'total_amount' => $this->faker->randomFloat(2, 100, 5000),
        ];
    }
}
