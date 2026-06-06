<?php

namespace Database\Factories;

use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonalManager>
 */
class PersonalManagerFactory extends Factory
{
    protected $model = PersonalManager::class;

    public function definition(): array
    {
        return [
            'erp_uuid' => Str::uuid()->toString(),
            'name' => $this->faker->name(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'email' => $this->faker->optional()->safeEmail(),
        ];
    }
}
