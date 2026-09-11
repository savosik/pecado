<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationParameterOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationParameterOrder>
 */
class MotivationParameterOrderFactory extends Factory
{
    protected $model = MotivationParameterOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_from' => '2026-10-01',
            'order_number' => '1-М',
            'order_date' => '2026-09-25',
            'values' => (array) config('motivation.default_parameters', []),
            'comment' => null,
            'author_id' => null,
        ];
    }
}
