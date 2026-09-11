<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationQuarterlyQualification;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationQuarterlyQualification>
 */
class MotivationQuarterlyQualificationFactory extends Factory
{
    protected $model = MotivationQuarterlyQualification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quarter_start' => '2026-10-01',
            'user_id' => User::factory(),
            'personal_manager_id' => PersonalManager::factory(),
            'shipments_amount' => '0.00',
            'returns_amount' => '0.00',
            'qualified' => false,
            'computed_at' => now(),
        ];
    }
}
