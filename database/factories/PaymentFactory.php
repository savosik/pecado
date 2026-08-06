<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 1000, 100000);

        return [
            'uuid' => (string) Str::uuid(),
            'number' => '29УТ-'.$this->faker->numerify('######'),
            'date' => $this->faker->dateTimeBetween('-1 year'),
            'direction' => Payment::DIRECTION_IN,
            'operation_code' => 'customer_payment',
            'operation_name' => 'Поступление оплаты от клиента',
            'document_type' => 'Платежное поручение',
            'contractor_uuid' => (string) Str::uuid(),
            'tax_id' => $this->faker->numerify('##########'),
            'bank_number' => $this->faker->numerify('####'),
            'bank_confirmed' => true,
            'amount' => $amount,
            'currency_code' => 'RUB',
            // Разнесение считает PaymentAllocationService; по умолчанию платёж — аванс.
            'allocated_amount' => 0,
            'unallocated_amount' => $amount,
        ];
    }

    /**
     * Возврат оплаты клиенту.
     */
    public function outgoing(): static
    {
        return $this->state(fn () => [
            'direction' => Payment::DIRECTION_OUT,
            'operation_code' => 'customer_refund',
            'operation_name' => 'Возврат оплаты клиенту',
        ]);
    }
}
