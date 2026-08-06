<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            // По умолчанию строка «осиротевшая»: реализация ещё не приехала из 1С.
            // Тесты, которым нужна связь, задают её через forShipment().
            'shipment_uuid' => (string) Str::uuid(),
            'shipment_id' => null,
            'order_uuid' => null,
            'amount' => $this->faker->randomFloat(2, 100, 10000),
            'line_number' => 1,
        ];
    }

    public function forShipment(\App\Models\Shipment $shipment): static
    {
        return $this->state(fn () => [
            'shipment_uuid' => $shipment->uuid,
            'shipment_id' => $shipment->id,
        ]);
    }
}
