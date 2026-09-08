<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationDebtExclusion>
 */
class MotivationDebtExclusionFactory extends Factory
{
    protected $model = MotivationDebtExclusion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipment_id' => null,
            'user_id' => User::factory(),
            'reason' => MotivationDebtExclusion::REASON_LEGAL,
            'excluded_from' => '2026-10-01',
            'excluded_until' => null,
            'amount' => null,
            'document_ref' => null,
            'comment' => null,
            'author_id' => null,
        ];
    }
}
