<?php

namespace Database\Factories;

use App\Enums\DefectClosedReason;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductDefect>
 */
class ProductDefectFactory extends Factory
{
    protected $model = ProductDefect::class;

    /**
     * По умолчанию — свежая партия от кладовщика: без цены и не опубликована.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory()->defect(),
            'defect_description' => fake()->randomElement([
                'Порвана упаковка',
                'Нет крышки батарейного отсека',
                'Потёртости на корпусе',
                'Помята коробка',
            ]),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => null,
            'is_published' => false,
            'closed_at' => null,
            'closed_reason' => null,
        ];
    }

    /** Партия с назначенной ценой, но ещё не опубликованная. */
    public function priced(?float $price = null): static
    {
        return $this->state(fn () => [
            'price' => $price ?? fake()->randomFloat(2, 100, 2000),
        ]);
    }

    /** Партия в продаже: цена назначена и публикация включена. */
    public function sellable(?float $price = null): static
    {
        return $this->priced($price)->state(fn () => ['is_published' => true]);
    }

    /** Закрытая партия — распродана или списана. */
    public function closed(DefectClosedReason $reason = DefectClosedReason::SOLD_OUT): static
    {
        return $this->state(fn () => [
            'closed_at' => now(),
            'closed_reason' => $reason,
        ]);
    }
}
