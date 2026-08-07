<?php

namespace Database\Factories;

use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentPaymentScheduleFactory extends Factory
{
    protected $model = ShipmentPaymentSchedule::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'line_number' => 1,
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $this->faker->randomFloat(2, 100, 10000),
            // По умолчанию строка не погашена: сумму проставляет только
            // PaymentScheduleService, тесты на UI задают её явно.
            'paid_amount' => 0,
            'percent' => 100,
            'term_days' => 30,
            'basis' => 'shipment_date',
            'basis_name' => 'от даты отгрузки',
            'stage' => 'credit',
            'stage_name' => 'Оплата после отгрузки',
            'order_uuid' => null,
        ];
    }

    public function forShipment(Shipment $shipment): static
    {
        return $this->state(fn () => ['shipment_id' => $shipment->id]);
    }

    /**
     * Просроченная строка — плановая дата в прошлом.
     */
    public function overdue(int $daysAgo = 10): static
    {
        return $this->state(fn () => ['due_date' => now()->subDays($daysAgo)->toDateString()]);
    }
}
