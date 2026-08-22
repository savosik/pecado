<?php

namespace Database\Factories;

use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use App\Models\CrmClientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmClientProfile>
 */
class CrmClientProfileFactory extends Factory
{
    protected $model = CrmClientProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'payment_behavior' => PaymentBehavior::PREPAY,
            'preferred_channel' => PreferredChannel::PHONE,
            'sentiment' => ClientSentiment::NEUTRAL,
        ];
    }
}
