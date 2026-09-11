<?php

namespace Tests\Feature\Crm\Motivation\Concerns;

use App\Models\PayrollInvoiceSettlement;
use App\Models\SettlementEntry;
use App\Models\Shipment;

/**
 * Накладная для вычета К1 так, как её видит расчёт: строка моста со сроком из графика
 * регистра, оплата по данным 1С в `shipments.paid_amount` и лента фактов регистра
 * (реализация минусом, поступления плюсом) — от неё считается потолок долга партнёра.
 */
trait RegistersInvoicesInLedger
{
    /**
     * @param  list<array{date: string, amount: float|int}>  $payments  платежи, которые мост нашёл и датировал
     * @param  float|null  $registryPaid  сколько 1С считает оплаченным; по умолчанию — сумма датированных платежей
     * @param  list<array{date: string, amount: float|int}>  $undatedCredits  зачёты и платежи без ссылки: в ленте регистра есть, мост их не видит
     * @param  array<string, mixed>  $bridge  переопределения строки моста
     */
    protected function ledgerInvoice(Shipment $shipment, string $dueOn, array $payments = [], ?float $registryPaid = null, array $undatedCredits = [], array $bridge = []): PayrollInvoiceSettlement
    {
        $total = (float) $shipment->total_amount;
        $dated = (float) array_sum(array_column($payments, 'amount'));
        $paid = $registryPaid ?? $dated;
        $status = $paid >= $total - SettlementEntry::EPSILON ? Shipment::PAYMENT_PAID : ($paid > 0 ? Shipment::PAYMENT_PARTIAL : Shipment::PAYMENT_UNPAID);

        $shipment->forceFill(['paid_amount' => $paid, 'payment_status' => $status])->save();

        $shippedOn = ($shipment->erp_created_at ?? $shipment->date)->toDateString();

        SettlementEntry::factory()->create([
            'user_id' => $shipment->user_id,
            'date' => $shippedOn,
            'document_date' => $shippedOn,
            'amount' => -1 * $total,
        ]);

        foreach (array_merge($payments, $undatedCredits) as $credit) {
            SettlementEntry::factory()->payment((float) $credit['amount'])->create([
                'user_id' => $shipment->user_id,
                'date' => $credit['date'],
                'document_date' => $credit['date'],
            ]);
        }

        return PayrollInvoiceSettlement::query()->create(array_replace([
            'shipment_id' => $shipment->id,
            'shipment_uuid' => $shipment->uuid,
            'user_id' => $shipment->user_id,
            'erp_number' => $shipment->erp_number,
            'total_amount' => $total,
            'shipped_on' => $shippedOn,
            'due_on' => $dueOn,
            'due_source' => PayrollInvoiceSettlement::DUE_SCHEDULE,
            'payments' => $payments === [] ? null : $payments,
            'matched_paid_amount' => $dated,
            'payment_status' => $status,
            'settled_on' => $dated >= $total - SettlementEntry::EPSILON ? end($payments)['date'] : null,
            'settled_source' => $dated >= $total - SettlementEntry::EPSILON ? PayrollInvoiceSettlement::SOURCE_MATCHED : null,
            'needs_review' => $status === Shipment::PAYMENT_PAID && $dated < $total - SettlementEntry::EPSILON,
        ], $bridge));
    }
}
