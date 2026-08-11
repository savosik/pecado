<?php

namespace Database\Factories;

use App\Models\SettlementDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementDocument>
 */
class SettlementDocumentFactory extends Factory
{
    protected $model = SettlementDocument::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'applied_revision' => 1,
            'applied_schedule_revision' => null,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-'.$this->faker->unique()->numberBetween(100000, 999999),
            'document_date' => now()->toDateString(),
            'is_reverted' => false,
            'last_posted_at' => now(),
        ];
    }

    /**
     * Проведение отменено: движений нет, но отметка живёт — иначе устаревшее
     * `settlement.posted` воскресило бы документ.
     */
    public function reverted(): static
    {
        return $this->state(fn () => [
            'is_reverted' => true,
            'last_posted_at' => null,
        ]);
    }
}
