<?php

namespace Database\Factories;

use App\Models\AgentTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentTopic>
 */
class AgentTopicFactory extends Factory
{
    protected $model = AgentTopic::class;

    public function definition(): array
    {
        return [
            'title' => 'Сверка данных: '.fake()->words(3, true),
            'task_body' => fake()->paragraph(),
        ];
    }
}
