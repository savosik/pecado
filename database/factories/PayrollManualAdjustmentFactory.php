<?php

namespace Database\Factories;

use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PayrollManualAdjustment>
 */
class PayrollManualAdjustmentFactory extends Factory
{
    protected $model = PayrollManualAdjustment::class;

    public function definition(): array
    {
        return [
            'personal_manager_id' => PersonalManager::factory(),
            'period_month' => PayrollManualAdjustment::normalizeMonth(Carbon::now()),
            'component_key' => PayrollManualAdjustment::COMPONENT_EXTRA_INCOME,
            'label' => 'ТГ-канал',
            'qty' => 1,
            'price' => 5000,
            'amount' => 5000,
            'comment' => null,
            'author_id' => User::factory(),
        ];
    }

    public function correction(float $amount, string $label = 'Корректировка'): static
    {
        return $this->state(fn (): array => [
            'component_key' => PayrollManualAdjustment::COMPONENT_MANUAL_CORRECTION,
            'label' => $label,
            'qty' => 1,
            'price' => $amount,
            'amount' => $amount,
        ]);
    }

    public function forMonth(string|Carbon $month): static
    {
        return $this->state(fn (): array => [
            'period_month' => PayrollManualAdjustment::normalizeMonth($month instanceof Carbon ? $month : Carbon::parse($month.'-01')),
        ]);
    }
}
