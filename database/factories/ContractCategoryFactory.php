<?php

namespace Database\Factories;

use App\Models\ContractCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractCategory>
 */
class ContractCategoryFactory extends Factory
{
    protected $model = ContractCategory::class;

    public function definition(): array
    {
        return [
            'name' => 'Категория '.fake()->unique()->numerify('###'),
            'description' => null,
            'organization_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
