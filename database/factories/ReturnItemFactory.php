<?php

namespace Database\Factories;

use App\Enums\ReturnReason;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReturnItem>
 */
class ReturnItemFactory extends Factory
{
    protected $model = ReturnItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = $this->faker->randomFloat(2, 100, 5000);
        $quantity = $this->faker->numberBetween(1, 10);

        return [
            'return_id' => ProductReturn::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'reason' => $this->faker->randomElement(ReturnReason::cases()),
            'reason_comment' => $this->faker->optional()->sentence(),
            'price' => $price,
            'subtotal' => $price * $quantity,
        ];
    }
}
