<?php

namespace Database\Factories;

use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PayrollCalculation>
 */
class PayrollCalculationFactory extends Factory
{
    protected $model = PayrollCalculation::class;

    public function definition(): array
    {
        return [
            'personal_manager_id' => PersonalManager::factory(),
            'period_month' => PayrollCalculation::normalizeMonth(Carbon::now()),
            'version' => 1,
            'status' => PayrollCalculation::STATUS_DRAFT,
            'scheme_id' => null,
            'params_effective' => [],
            'inputs' => [],
            'breakdown' => ['components' => [], 'total' => 0, 'warnings' => []],
            'total' => 0,
            'forecast' => null,
            'inputs_hash' => null,
            'computed_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PayrollCalculation::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    public function forMonth(string|Carbon $month): static
    {
        return $this->state(fn (): array => [
            'period_month' => PayrollCalculation::normalizeMonth($month instanceof Carbon ? $month : Carbon::parse($month.'-01')),
        ]);
    }
}
