<?php

namespace Database\Factories;

use App\Enums\UserKind;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'user_kind' => UserKind::CLIENT,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Сотрудник компании: в CRM среди клиентов такому места нет.
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_kind' => UserKind::STAFF,
        ]);
    }

    /**
     * Служебная (техническая) учётка: боты, выгрузки, интеграции.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_kind' => UserKind::SERVICE,
        ]);
    }
}
