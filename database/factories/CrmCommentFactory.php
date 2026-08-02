<?php

namespace Database\Factories;

use App\Models\CrmComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CrmComment>
 */
class CrmCommentFactory extends Factory
{
    protected $model = CrmComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commentable_type' => User::class,
            'commentable_id' => User::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'is_pinned' => false,
        ];
    }

    /**
     * Комментарий на конкретной сущности. client_user_id проставит сама модель.
     */
    public function on(Model $entity): static
    {
        return $this->state(fn () => [
            'commentable_type' => $entity::class,
            'commentable_id' => $entity->getKey(),
        ]);
    }

    public function by(User $author): static
    {
        return $this->state(fn () => ['user_id' => $author->getKey()]);
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }
}
