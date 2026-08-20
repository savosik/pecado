<?php

namespace Database\Factories;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientContact>
 */
class ClientContactFactory extends Factory
{
    protected $model = ClientContact::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => null,
            'full_name' => $this->faker->name(),
            'role' => ClientContactRole::ACCOUNTANT,
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => null,
            'is_primary' => false,
            'is_active' => true,
            'marketing_consent' => false,
            'source' => ClientContact::SOURCE_MANUAL,
        ];
    }

    public function role(ClientContactRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    /** Черновик из профиля CRM: в рассылке не участвует до подтверждения. */
    public function draft(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'source' => ClientContact::SOURCE_PROFILE_IMPORT,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => ['unsubscribed_at' => now()]);
    }
}
