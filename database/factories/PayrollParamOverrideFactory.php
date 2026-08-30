<?php

namespace Database\Factories;

use App\Models\PayrollParamOverride;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PayrollParamOverride>
 */
class PayrollParamOverrideFactory extends Factory
{
    protected $model = PayrollParamOverride::class;

    public function definition(): array
    {
        return [
            'personal_manager_id' => PersonalManager::factory(),
            'period_month' => PayrollParamOverride::periodKey(Carbon::now()),
            'component_key' => 'salary',
            'params' => ['amount' => 75000],
            'updated_by_user_id' => null,
            'comment' => null,
        ];
    }

    public function permanent(): static
    {
        return $this->state(fn (): array => ['period_month' => PayrollParamOverride::permanentMonth()]);
    }

    public function forMonth(string|Carbon $month): static
    {
        return $this->state(fn (): array => [
            'period_month' => PayrollParamOverride::periodKey($month instanceof Carbon ? $month : Carbon::parse($month.'-01')),
        ]);
    }
}
