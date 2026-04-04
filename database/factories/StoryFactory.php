<?php

namespace Database\Factories;

use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StoryFactory extends Factory
{
    protected $model = Story::class;

    public function definition(): array
    {
        $name = fake()->sentence(3);

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->randomNumber(4),
            'is_active' => true,
            'is_published' => true,
            'show_name' => fake()->boolean(),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
