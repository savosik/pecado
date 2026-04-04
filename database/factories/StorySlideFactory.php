<?php

namespace Database\Factories;

use App\Models\Story;
use App\Models\StorySlide;
use Illuminate\Database\Eloquent\Factories\Factory;

class StorySlideFactory extends Factory
{
    protected $model = StorySlide::class;

    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'title' => fake()->sentence(3),
            'content' => fake()->paragraph(),
            'button_text' => fake()->optional()->word(),
            'button_url' => fake()->optional()->url(),
            'duration' => fake()->numberBetween(3, 15),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
