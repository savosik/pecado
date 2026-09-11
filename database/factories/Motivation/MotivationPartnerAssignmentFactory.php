<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationPartnerAssignment>
 */
class MotivationPartnerAssignmentFactory extends Factory
{
    protected $model = MotivationPartnerAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'personal_manager_id' => PersonalManager::factory(),
            'starts_on' => '2026-10-01',
            'ends_on' => null,
            'reason' => MotivationPartnerAssignment::REASON_INITIAL,
            'plan_delta' => null,
            'comment' => null,
            'author_id' => null,
        ];
    }

    /**
     * Партнёр в Пуле: закрепления нет.
     */
    public function pool(): self
    {
        return $this->state(fn () => [
            'personal_manager_id' => null,
            'reason' => MotivationPartnerAssignment::REASON_RETURN_TO_POOL,
        ]);
    }
}
