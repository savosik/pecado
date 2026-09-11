<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationPlanOrder>
 */
class MotivationPlanOrderFactory extends Factory
{
    protected $model = MotivationPlanOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quarter_start' => '2026-10-01',
            'personal_manager_id' => PersonalManager::factory(),
            'version' => 1,
            'median_per_day' => '0.00',
            'working_days' => ['2026-10-01' => 22, '2026-11-01' => 20, '2026-12-01' => 22],
            'seasonal' => ['2026-10-01' => 1.0, '2026-11-01' => 1.0, '2026-12-01' => 1.0],
            'growth_rate' => '0.00000',
            'overperformance_carry' => null,
            'base_change' => null,
            'previous_quarter_total' => null,
            'decline_limited' => false,
            'decline_limit_waived_reason' => null,
            'values' => ['2026-10-01' => 0, '2026-11-01' => 0, '2026-12-01' => 0],
            'previous_values' => null,
            'status' => MotivationPlanOrder::STATUS_DRAFT,
            'comment' => null,
            'author_id' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}
