<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' — Склад',
            'external_id' => (string) Str::uuid(),
            'is_defect' => false,
        ];
    }

    /**
     * Склад некондиции — с него отгружаются заказы уценки.
     */
    public function defect(): static
    {
        return $this->state(fn () => [
            'name' => 'Москва некондиция',
            'is_defect' => true,
        ]);
    }
}
