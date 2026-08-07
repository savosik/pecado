<?php

namespace Database\Factories;

use App\Models\GoodsIssue;
use App\Models\GoodsIssuePackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoodsIssuePackageFactory extends Factory
{
    protected $model = GoodsIssuePackage::class;

    public function definition(): array
    {
        return [
            'goods_issue_id' => GoodsIssue::factory(),
            'number' => 1,
            'positions_count' => $this->faker->numberBetween(1, 5),
        ];
    }
}
