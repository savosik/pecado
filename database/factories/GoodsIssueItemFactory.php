<?php

namespace Database\Factories;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GoodsIssueItemFactory extends Factory
{
    protected $model = GoodsIssueItem::class;

    public function definition(): array
    {
        return [
            'goods_issue_id' => GoodsIssue::factory(),
            'line_number' => 1,
            'product_uuid' => (string) Str::uuid(),
            'product_name' => $this->faker->words(4, true),
            'order_uuid' => (string) Str::uuid(),
            'order_number' => '30УТ-'.$this->faker->numerify('######'),
            'quantity' => $this->faker->numberBetween(1, 50),
            'unit' => 'шт',
        ];
    }
}
