<?php

namespace Database\Factories\Motivation;

use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationPartnerNovelty>
 */
class MotivationPartnerNoveltyFactory extends Factory
{
    protected $model = MotivationPartnerNovelty::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_shipment_on' => null,
            'last_shipment_before_gap_on' => null,
            'gap_days' => null,
            'novelty_started_on' => null,
            'novelty_ends_on' => null,
            'source' => MotivationPartnerNovelty::SOURCE_UNKNOWN,
            'history_incomplete' => false,
            'computed_at' => now(),
        ];
    }

    /**
     * Партнёр в Периоде новизны: его отгрузки идут в показатель П2.
     */
    public function withinNovelty(string $startedOn = '2026-09-01', string $endsOn = '2026-11-30'): self
    {
        return $this->state(fn () => [
            'first_shipment_on' => $startedOn,
            'novelty_started_on' => $startedOn,
            'novelty_ends_on' => $endsOn,
        ]);
    }
}
