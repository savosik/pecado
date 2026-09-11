<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyShare;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationQuarterlyShare>
 */
class MotivationQuarterlyShareFactory extends Factory
{
    protected $model = MotivationQuarterlyShare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bonus_id' => MotivationQuarterlyBonus::factory(),
            'personal_manager_id' => PersonalManager::factory(),
            'amount' => '0.00',
            'reason' => null,
            'author_id' => null,
        ];
    }
}
