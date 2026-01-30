<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandStory;
use Illuminate\Database\Eloquent\Factories\Factory;

class BrandStoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BrandStory::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'brand_id' => Brand::factory(),
            'title' => $this->faker->sentence,
            'slug' => $this->faker->slug,
            'short_description' => $this->faker->sentence,
            'detailed_description' => $this->faker->paragraphs(3, true),
            'meta_title' => $this->faker->sentence,
            'meta_description' => $this->faker->paragraph,
        ];
    }
}
