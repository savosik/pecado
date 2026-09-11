<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationPoolPackage;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationPoolPackage>
 */
class MotivationPoolPackageFactory extends Factory
{
    protected $model = MotivationPoolPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'personal_manager_id' => PersonalManager::factory(),
            'issued_on' => '2026-10-01',
            'issued_by' => null,
            'contact_due_on' => '2026-10-15',
            'shipment_due_on' => '2026-12-30',
            'status' => MotivationPoolPackage::STATUS_ACTIVE,
            'comment' => null,
        ];
    }
}
