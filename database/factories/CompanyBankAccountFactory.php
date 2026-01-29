<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompanyBankAccount>
 */
class CompanyBankAccountFactory extends Factory
{
    protected $model = CompanyBankAccount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'bank_name' => fake()->randomElement([
                'Сбербанк',
                'ВТБ',
                'Альфа-Банк',
                'Тинькофф Банк',
                'Газпромбанк',
                'Россельхозбанк',
            ]),
            'bank_bik' => fake()->numerify('04#######'),
            'correspondent_account' => fake()->numerify('30101810#############'),
            'account_number' => fake()->numerify('40702810#############'),
            'is_primary' => false,
        ];
    }

    /**
     * Set as primary bank account.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
