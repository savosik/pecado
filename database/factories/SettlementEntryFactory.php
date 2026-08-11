<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementEntry>
 */
class SettlementEntryFactory extends Factory
{
    protected $model = SettlementEntry::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'revision' => 1,
            'source' => 'erp',
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'date' => now()->toDateString(),
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'organization_id' => Organization::factory(),
            'contractor_uuid' => $this->faker->uuid(),
            'organization_uuid' => $this->faker->uuid(),
            // Реализация уменьшает баланс: клиент стал должен больше.
            'amount' => -1 * $this->faker->randomFloat(2, 1000, 100000),
            'currency_code' => 'RUB',
            'settled_amount' => 0,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-'.$this->faker->unique()->numberBetween(100000, 999999),
            'document_date' => now()->toDateString(),
        ];
    }

    public function payment(float $amount = 50000): static
    {
        return $this->state(fn () => [
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => abs($amount),
            'document_kind' => 'payment',
            'movement_kind' => 'expense',
        ]);
    }

    /**
     * Плановая строка графика оплаты. Сумма положительная — это «сколько клиент
     * должен заплатить», а не движение баланса.
     */
    public function plan(float $amount = 100000, float $settled = 0): static
    {
        return $this->state(fn () => [
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => abs($amount),
            'settled_amount' => $settled,
        ]);
    }

    /**
     * Просроченная плановая строка — дата в прошлом, деньги не пришли.
     */
    public function overdue(int $daysAgo = 10): static
    {
        return $this->plan()->state(fn () => [
            'date' => now()->subDays($daysAgo)->toDateString(),
        ]);
    }
}
