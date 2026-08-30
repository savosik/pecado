<?php

namespace App\Services\Payroll\Dto;

/**
 * Накладная во входах расчёта: закрытая с задержкой (штраф) или ещё неоплаченная (риск).
 */
final class InvoiceInput
{
    public function __construct(
        public readonly int $shipmentId,
        public readonly ?string $erpNumber,
        public readonly ?int $partnerId,
        public readonly string $partnerName,
        public readonly float $amount,
        public readonly ?string $shippedOn,
        public readonly ?string $dueOn,
        public readonly ?string $settledOn,
        public readonly ?int $delayWorkingDays,
        public readonly ?int $delayCalendarDays,
        public readonly ?string $source,
        public readonly ?string $paymentStatus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'erp_number' => $this->erpNumber,
            'partner_id' => $this->partnerId,
            'partner_name' => $this->partnerName,
            'amount' => $this->amount,
            'shipped_on' => $this->shippedOn,
            'due_on' => $this->dueOn,
            'settled_on' => $this->settledOn,
            'delay_working_days' => $this->delayWorkingDays,
            'delay_calendar_days' => $this->delayCalendarDays,
            'source' => $this->source,
            'payment_status' => $this->paymentStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            shipmentId: (int) ($data['shipment_id'] ?? 0),
            erpNumber: isset($data['erp_number']) ? (string) $data['erp_number'] : null,
            partnerId: isset($data['partner_id']) ? (int) $data['partner_id'] : null,
            partnerName: (string) ($data['partner_name'] ?? ''),
            amount: (float) ($data['amount'] ?? 0),
            shippedOn: isset($data['shipped_on']) ? (string) $data['shipped_on'] : null,
            dueOn: isset($data['due_on']) ? (string) $data['due_on'] : null,
            settledOn: isset($data['settled_on']) ? (string) $data['settled_on'] : null,
            delayWorkingDays: isset($data['delay_working_days']) ? (int) $data['delay_working_days'] : null,
            delayCalendarDays: isset($data['delay_calendar_days']) ? (int) $data['delay_calendar_days'] : null,
            source: isset($data['source']) ? (string) $data['source'] : null,
            paymentStatus: isset($data['payment_status']) ? (string) $data['payment_status'] : null,
        );
    }

    /**
     * Копия с изменёнными полями — для what-if прогноза и советов.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }
}
