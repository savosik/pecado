<?php

namespace Database\Factories;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CrmEmail>
 */
class CrmEmailFactory extends Factory
{
    protected $model = CrmEmail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'to' => [fake()->safeEmail()],
            'cc' => null,
            'reply_to' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'body_html' => '<p>'.fake()->paragraph().'</p>',
            'status' => EmailStatus::DRAFT,
        ];
    }

    /**
     * Письмо по сущности. client_user_id проставит сама модель.
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

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => EmailStatus::SENT,
            'sent_at' => now(),
            'message_id' => '<'.fake()->uuid().'@pecado.ru>',
        ]);
    }
}
