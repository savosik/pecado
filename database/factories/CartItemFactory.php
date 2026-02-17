<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 10),
            'price' => fake()->randomFloat(2, 10, 1000),
            'item_type' => 'instock',
            'warehouse_id' => null,
        ];
    }

    /**
     * Indicate the item is instock.
     */
    public function instock(): static
    {
        return $this->state(fn () => ['item_type' => 'instock']);
    }

    /**
     * Indicate the item is preorder.
     */
    public function preorder(): static
    {
        return $this->state(fn () => ['item_type' => 'preorder']);
    }

    /**
     * Assign a warehouse.
     */
    public function withWarehouse(): static
    {
        return $this->state(fn () => ['warehouse_id' => Warehouse::factory()]);
    }
}
