<?php

namespace Database\Factories;

use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use App\Models\CrmCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CrmCall>
 */
class CrmCallFactory extends Factory
{
    protected $model = CrmCall::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'direction' => CallDirection::OUTGOING,
            'result' => CallResult::TALKED,
            'phone' => fake()->numerify('+7##########'),
            'summary' => fake()->optional()->sentence(),
            'started_at' => now(),
            'provider' => CrmCall::PROVIDER_MANUAL,
        ];
    }

    /**
     * Звонок, привязанный к сущности. client_user_id проставит сама модель.
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
        return $this->state(fn () => ['user_id' => $author->getKey()]);
    }

    public function incoming(): static
    {
        return $this->state(fn () => ['direction' => CallDirection::INCOMING]);
    }

    public function noAnswer(): static
    {
        return $this->state(fn () => [
            'result' => CallResult::NO_ANSWER,
            'summary' => null,
        ]);
    }
}
