<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agreement>
 */
class AgreementFactory extends Factory
{
    protected $model = Agreement::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'organization_id' => Organization::factory(),
            'partner_uuid' => $this->faker->uuid(),
            'contractor_uuid' => $this->faker->uuid(),
            'organization_uuid' => $this->faker->uuid(),
            'number' => 'СГ-'.$this->faker->unique()->numberBetween(1000, 9999),
            'date' => now()->subYear()->toDateString(),
            'name' => 'Соглашение об условиях продаж',
            'currency_code' => 'RUB',
            'settlement_procedure' => 'orders',
            'credit_limit' => null,
            'deferral_days' => 30,
            'status' => Agreement::STATUS_ACTIVE,
            'revision' => 1,
        ];
    }

    /**
     * Соглашение, приехавшее раньше контрагента: связи не проставлены, но сырые
     * UUID на месте. Штатная ситуация — порядок доставки очередей не гарантирован.
     */
    public function unmatched(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'company_id' => null,
            'organization_id' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => Agreement::STATUS_CLOSED]);
    }
}
