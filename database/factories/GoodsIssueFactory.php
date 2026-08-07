<?php

namespace Database\Factories;

use App\Models\GoodsIssue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GoodsIssueFactory extends Factory
{
    protected $model = GoodsIssue::class;

    public function definition(): array
    {
        $date = $this->faker->dateTimeBetween('-30 days');

        return [
            'uuid' => (string) Str::uuid(),
            'number' => 'УТ-'.$this->faker->unique()->numerify('########'),
            'date' => $date,
            'shipment_date' => $date,
            'status' => $this->faker->randomElement(GoodsIssue::STATUSES),
            'status_changed_at' => $date,
            'operation' => 'Отгрузка клиенту',
            'recipient_name' => $this->faker->company(),
            'responsible' => 'Отгрузка '.$this->faker->numberBetween(1, 5).' Москва',
            'priority' => GoodsIssue::PRIORITY_NORMAL,
            'delivery_type' => GoodsIssue::DELIVERY_DELIVERY,
        ];
    }

    /**
     * Ордер, зависший в текущем статусе.
     */
    public function stale(string $status = GoodsIssue::STATUS_TO_PICK): self
    {
        return $this->state(fn () => [
            'status' => $status,
            'status_changed_at' => now()->subHours(GoodsIssue::STALE_HOURS + 1),
        ]);
    }
}
