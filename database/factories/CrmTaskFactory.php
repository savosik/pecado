<?php

namespace Database\Factories;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CrmTask>
 */
class CrmTaskFactory extends Factory
{
    protected $model = CrmTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'author_id' => User::factory(),
            'assignee_id' => User::factory(),
            'status' => TaskStatus::OPEN,
            'priority' => TaskPriority::NORMAL,
            'due_at' => null,
        ];
    }

    /**
     * Задача, привязанная к сущности. client_user_id проставит сама модель.
     */
    public function on(Model $entity): static
    {
        return $this->state(fn () => [
            'related_type' => $entity::class,
            'related_id' => $entity->getKey(),
        ]);
    }

    public function by(User $author): static
    {
        return $this->state(fn () => ['author_id' => $author->getKey()]);
    }

    public function assignedTo(User $assignee): static
    {
        return $this->state(fn () => ['assignee_id' => $assignee->getKey()]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::OPEN,
            'due_at' => now()->subDays(2),
        ]);
    }
}
