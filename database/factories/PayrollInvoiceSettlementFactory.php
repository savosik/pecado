<?php

namespace Database\Factories;

use App\Models\PayrollInvoiceSettlement;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollInvoiceSettlement>
 */
class PayrollInvoiceSettlementFactory extends Factory
{
    protected $model = PayrollInvoiceSettlement::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'shipment_uuid' => $this->faker->uuid(),
            'erp_number' => '29УТ-'.$this->faker->unique()->numerify('######'),
            'number_key' => null,
            'user_id' => null,
            'company_id' => null,
            'personal_manager_id' => null,
            'shipped_on' => now()->toDateString(),
            'total_amount' => 10000,
            'due_on' => null,
            'due_source' => null,
            'matched_paid_amount' => 0,
            'matched_settled_on' => null,
            'payments' => null,
            'payment_status' => Shipment::PAYMENT_UNPAID,
            'settled_on' => null,
            'settled_source' => null,
            'delay_calendar_days' => null,
            'delay_working_days' => null,
            'needs_review' => false,
            'computed_at' => now(),
        ];
    }

    /**
     * Закрыта с задержкой: срок, дата закрытия и дни заданы явно.
     */
    public function settledLate(string $dueOn, string $settledOn, int $workingDays, int $calendarDays): static
    {
        return $this->state(fn (): array => [
            'due_on' => $dueOn,
            'due_source' => PayrollInvoiceSettlement::DUE_SCHEDULE,
            'matched_settled_on' => $settledOn,
            'settled_on' => $settledOn,
            'settled_source' => PayrollInvoiceSettlement::SOURCE_MATCHED,
            'delay_working_days' => $workingDays,
            'delay_calendar_days' => $calendarDays,
            'payment_status' => Shipment::PAYMENT_PAID,
        ]);
    }
}
