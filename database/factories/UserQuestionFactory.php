<?php

namespace Database\Factories;

use App\Enums\UserQuestionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserQuestion>
 */
class UserQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'subject' => $this->faker->sentence(6),
            'body' => $this->faker->paragraph(),
            'status' => UserQuestionStatus::NEW,
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }

    public function inProgress(): self
    {
        return $this->state(fn () => ['status' => UserQuestionStatus::IN_PROGRESS]);
    }

    public function answered(): self
    {
        return $this->state(fn () => [
            'status' => UserQuestionStatus::ANSWERED,
            'answer' => $this->faker->paragraph(),
            'answered_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn () => [
            'status' => UserQuestionStatus::REJECTED,
            'rejected_reason' => 'Спам',
        ]);
    }
}
