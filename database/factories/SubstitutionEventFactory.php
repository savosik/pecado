<?php

namespace Database\Factories;

use App\Enums\Substitution\SignalEvent;
use App\Models\SubstitutionEvent;
use App\Models\SubstitutionOfferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubstitutionEvent>
 */
class SubstitutionEventFactory extends Factory
{
    protected $model = SubstitutionEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_item_id' => SubstitutionOfferItem::factory(),
            'event' => SignalEvent::CLIENT_CHOSEN,
        ];
    }
}
