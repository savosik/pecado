<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationPoolPackage;
use App\Models\Motivation\MotivationPoolPackageItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationPoolPackageItem>
 */
class MotivationPoolPackageItemFactory extends Factory
{
    protected $model = MotivationPoolPackageItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => MotivationPoolPackage::factory(),
            'user_id' => User::factory(),
            'first_contact_at' => null,
            'first_shipment_at' => null,
            'returned_to_pool_at' => null,
            'outcome' => MotivationPoolPackageItem::OUTCOME_IN_PROGRESS,
        ];
    }
}
