<?php

namespace Database\Factories;

use App\Enums\Shortage\ShortageReasonCategory;
use App\Models\ShortageReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShortageReason>
 */
class ShortageReasonFactory extends Factory
{
    protected $model = ShortageReason::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Причина '.fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(ShortageReasonCategory::cases()),
            'sort_order' => fake()->numberBetween(100, 900),
            'is_active' => true,
            'is_system' => false,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function system(): self
    {
        return $this->state(fn () => ['is_system' => true]);
    }
}
