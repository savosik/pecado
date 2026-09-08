<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationFocusRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationFocusRule>
 */
class MotivationFocusRuleFactory extends Factory
{
    protected $model = MotivationFocusRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => MotivationFocusRule::SCOPE_BRAND,
            'target_id' => 1,
            'rate' => null,
            'starts_on' => '2026-10-01',
            'ends_on' => null,
            'order_number' => null,
            'order_date' => null,
            'comment' => null,
            'author_id' => null,
        ];
    }
}
