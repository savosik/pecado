<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationQuarterlyBonus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationQuarterlyBonus>
 */
class MotivationQuarterlyBonusFactory extends Factory
{
    protected $model = MotivationQuarterlyBonus::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quarter_start' => '2026-10-01',
            'qualified_count' => 0,
            'step_reached' => 0,
            'amount' => '0.00',
            'status' => MotivationQuarterlyBonus::STATUS_DRAFT,
            'snapshot' => null,
        ];
    }
}
