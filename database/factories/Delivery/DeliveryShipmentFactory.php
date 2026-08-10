<?php

namespace Database\Factories\Delivery;

use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Models\Delivery\DeliveryShipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryShipment>
 */
class DeliveryShipmentFactory extends Factory
{
    protected $model = DeliveryShipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => DeliveryShipmentStatus::DRAFT,
            'delivery_type' => DeliveryShipment::DELIVERY_TYPE_DOOR,
            'pickup_type' => DeliveryShipment::PICKUP_TYPE_COURIER,
            'calculated_weight' => 2500,
            'places_count' => 1,
            'assessed_cost' => 10000,
            'recipient' => [
                'contactName' => 'Иван Петров',
                'phone' => '+79000000000',
                'countryCode' => 'RU',
                'region' => 'г Москва',
                'city' => 'Москва',
                'street' => 'ул Нижняя Красносельская',
                'house' => '35',
                'index' => '105066',
            ],
            'recipient_city' => 'Москва',
            'recipient_contact' => 'Иван Петров',
        ];
    }

    /**
     * Черновик с выбранным тарифом — состояние прямо перед передачей заявки.
     */
    public function calculated(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryShipmentStatus::CALCULATED,
            'provider_key' => 'cdek',
            'tariff_id' => 137,
            'tariff_name' => 'Посылка склад-склад',
            'delivery_cost' => 750.00,
        ]);
    }

    /**
     * Заявка уже у перевозчика.
     */
    public function submitted(): self
    {
        return $this->calculated()->state(fn (): array => [
            'status' => DeliveryShipmentStatus::SUBMITTED,
            'apiship_order_id' => (string) $this->faker->numberBetween(1000000, 9999999),
            'submitted_at' => now(),
        ]);
    }
}
