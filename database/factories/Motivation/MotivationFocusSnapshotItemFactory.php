<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationFocusSnapshotItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationFocusSnapshotItem>
 */
class MotivationFocusSnapshotItemFactory extends Factory
{
    protected $model = MotivationFocusSnapshotItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_month' => '2026-10-01',
            'product_id' => Product::factory(),
            'rule_id' => MotivationFocusRule::factory(),
            'rate' => '0.01000',
        ];
    }
}
