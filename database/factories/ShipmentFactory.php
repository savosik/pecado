<?php

namespace Database\Factories;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'tax_id' => $this->faker->numerify('##########'),
            'date' => $this->faker->date(),
            'status' => 'completed',
            'currency_code' => $this->faker->randomElement(['RUB', 'KZT', 'BYN']),
            'total_amount' => $this->faker->randomFloat(2, 1000, 100000),
        ];
    }
}
