<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationObjection;
use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationObjection>
 */
class MotivationObjectionFactory extends Factory
{
    protected $model = MotivationObjection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calculation_id' => PayrollCalculation::factory(),
            'personal_manager_id' => PersonalManager::factory(),
            'author_id' => null,
            'reason' => 'Не учтена отгрузка партнёру от 12-го числа',
            'status' => MotivationObjection::STATUS_OPEN,
            'response' => null,
            'responded_by' => null,
            'responded_at' => null,
        ];
    }
}
