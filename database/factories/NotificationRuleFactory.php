<?php

namespace Database\Factories;

use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    protected $model = NotificationRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Правило '.$this->faker->word(),
            'event_key' => 'orders.status_changed',
            'scope_type' => NotificationRule::SCOPE_GLOBAL,
            'priority' => 100,
            'stop_processing' => false,
            'is_active' => true,
            'is_system' => false,
            'channel' => 'email',
            'digest' => 'none',
        ];
    }

    public function forUser(int $userId): static
    {
        return $this->state(fn () => [
            'scope_type' => NotificationRule::SCOPE_USER,
            'scope_user_id' => $userId,
        ]);
    }

    public function forCompany(int $companyId): static
    {
        return $this->state(fn () => [
            'scope_type' => NotificationRule::SCOPE_COMPANY,
            'scope_company_id' => $companyId,
        ]);
    }

    public function stopping(): static
    {
        return $this->state(fn () => ['stop_processing' => true]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function event(string $key): static
    {
        return $this->state(fn () => ['event_key' => $key]);
    }
}
