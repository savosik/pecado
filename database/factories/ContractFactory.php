<?php

namespace Database\Factories;

use App\Enums\Crm\ContractPaymentTerms;
use App\Enums\Crm\ContractStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'category_id' => ContractCategory::factory(),
            'user_id' => null,
            'company_id' => null,
            'counterparty_name' => fake()->company(),
            'number' => '№ '.fake()->unique()->numberBetween(1, 9999).'-Т/2026',
            'date' => fake()->dateTimeBetween('-1 year'),
            'status' => ContractStatus::SIGNED,
            'payment_terms' => ContractPaymentTerms::DEFERRAL,
            'is_visible_in_cabinet' => true,
        ];
    }

    /**
     * Договор с юрлицом партнёра: партнёр подтянется из контрагента.
     */
    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->getKey(),
            'user_id' => $company->user_id,
            'counterparty_name' => $company->name,
        ]);
    }

    public function forClient(User $client): static
    {
        return $this->state(fn (): array => ['user_id' => $client->getKey()]);
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => ['status' => ContractStatus::TERMINATED]);
    }
}
