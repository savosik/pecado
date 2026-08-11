<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SettlementCheckpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementCheckpoint>
 */
class SettlementCheckpointFactory extends Factory
{
    protected $model = SettlementCheckpoint::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'organization_id' => Organization::factory(),
            'contractor_uuid' => $this->faker->uuid(),
            'organization_uuid' => $this->faker->uuid(),
            'as_of_date' => '2026-07-01',
            'currency_code' => 'RUB',
            'amount' => -1 * $this->faker->randomFloat(2, 1000, 500000),
            'is_verified' => true,
        ];
    }

    /**
     * Начало ленты движений — техническая точка, бухгалтерией не сверенная.
     */
    public function openingBalance(): static
    {
        return $this->state(fn () => [
            'as_of_date' => '2026-01-01',
            'is_verified' => false,
        ]);
    }
}
