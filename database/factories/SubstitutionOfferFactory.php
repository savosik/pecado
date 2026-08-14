<?php

namespace Database\Factories;

use App\Enums\Substitution\OfferStatus;
use App\Models\Order;
use App\Models\SubstitutionOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubstitutionOffer>
 */
class SubstitutionOfferFactory extends Factory
{
    protected $model = SubstitutionOffer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'company_id' => null,
            'manager_user_id' => User::factory(),
            'status' => OfferStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function viewed(): static
    {
        return $this->state(fn () => [
            'status' => OfferStatus::VIEWED,
            'viewed_at' => now(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => OfferStatus::CONFIRMED,
            'viewed_at' => now()->subHour(),
            'confirmed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => OfferStatus::EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
    }
}
