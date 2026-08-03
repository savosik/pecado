<?php

namespace Database\Factories;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CrmSalesPlan>
 */
class CrmSalesPlanFactory extends Factory
{
    protected $model = CrmSalesPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_month' => CrmSalesPlan::normalizeMonth(Carbon::now()),
            'target_type' => PlanTarget::DEPARTMENT,
            'target_id' => 0,
            'amount' => fake()->randomFloat(2, 100_000, 5_000_000),
            'author_id' => User::factory(),
            'comment' => null,
        ];
    }

    public function forMonth(string|Carbon $month): static
    {
        return $this->state(fn (): array => [
            'period_month' => CrmSalesPlan::normalizeMonth(
                $month instanceof Carbon ? $month : Carbon::parse($month.'-01'),
            ),
        ]);
    }

    public function forManager(PersonalManager $manager): static
    {
        return $this->state(fn (): array => [
            'target_type' => PlanTarget::MANAGER,
            'target_id' => $manager->getKey(),
        ]);
    }

    public function forClient(User $client): static
    {
        return $this->state(fn (): array => [
            'target_type' => PlanTarget::CLIENT,
            'target_id' => $client->getKey(),
        ]);
    }
}
