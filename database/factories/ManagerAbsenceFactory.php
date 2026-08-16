<?php

namespace Database\Factories;

use App\Enums\Crm\ManagerAbsenceType;
use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagerAbsence>
 */
class ManagerAbsenceFactory extends Factory
{
    protected $model = ManagerAbsence::class;

    public function definition(): array
    {
        $starts = $this->faker->dateTimeBetween('-1 week', '+1 week');

        return [
            'personal_manager_id' => PersonalManager::factory(),
            'substitute_manager_id' => null,
            'type' => ManagerAbsenceType::VACATION,
            'starts_on' => $starts->format('Y-m-d'),
            'ends_on' => $starts->modify('+13 days')->format('Y-m-d'),
            'comment' => null,
            'created_by' => null,
        ];
    }
}
