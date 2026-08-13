<?php

namespace Database\Factories;

use App\Models\CrmLeadStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmLeadStage>
 */
class CrmLeadStageFactory extends Factory
{
    protected $model = CrmLeadStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'color' => 'gray',
            'position' => fake()->numberBetween(1, 100),
            'is_won' => false,
            'is_lost' => false,
            'is_active' => true,
        ];
    }

    public function won(): static
    {
        return $this->state(fn () => ['name' => 'Выиграли', 'is_won' => true, 'color' => 'green']);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['name' => 'Проиграли', 'is_lost' => true, 'color' => 'red']);
    }

    /**
     * Скрытая стадия: на доску не попадает, но у старых лидов остаётся.
     */
    public function hidden(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
