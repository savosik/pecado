<?php

namespace App\Support\Payments;

use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;

/**
 * Блок «График оплаты» в карточке реализации.
 *
 * Один презентер на три интерфейса (кабинет, CRM, админка): график везде показывается
 * одинаково — он read-only и мастером является 1С. Расходится только валюта: кабинет
 * пересчитывает суммы в валюту клиента, сотрудники видят их как в документе,
 * поэтому пересчёт передаётся колбэком, а не зашит внутрь.
 */
class PaymentSchedulePresenter
{
    /**
     * @param  (callable(float): float)|null  $convert  Пересчёт суммы в валюту показа
     * @return array<string, mixed>|null null — 1С не присылала график по этой реализации
     */
    public static function forShipment(Shipment $shipment, ?callable $convert = null): ?array
    {
        $lines = $shipment->relationLoaded('paymentSchedules')
            ? $shipment->paymentSchedules
            : $shipment->paymentSchedules()->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $convert ??= static fn (float $amount): float => $amount;

        $total = round((float) $lines->sum('amount'), 2);
        // Аванс по заказу закрывает строку наравне с прямым разнесением — ровно так
        // считает ShipmentPaymentSchedule::unpaid_amount. Забыв про prepaid_amount
        // здесь, шапка показывала бы долг больше, чем даёт сумма строк под ней.
        $paid = round((float) $lines->sum('paid_amount') + (float) $lines->sum('prepaid_amount'), 2);
        // Итог собирается из строк, а не из разницы сумм: остаток строки клампится
        // в ноль, и переплата по одной строке не должна гасить долг по другой.
        $unpaid = round((float) $lines->sum(static fn (ShipmentPaymentSchedule $line): float => $line->unpaid_amount), 2);
        $documentTotal = round((float) $shipment->total_amount, 2);

        return [
            'lines' => $lines->map(fn (ShipmentPaymentSchedule $line): array => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'due_date' => $line->due_date?->toDateString(),
                'due_date_label' => $line->due_date?->format('d.m.Y'),
                'amount' => $convert((float) $line->amount),
                'paid_amount' => $convert((float) $line->paid_amount),
                'unpaid_amount' => $convert($line->unpaid_amount),
                'percent' => $line->percent !== null ? (float) $line->percent : null,
                'term_days' => $line->term_days,
                'basis_name' => $line->basis_name,
                'stage_name' => $line->stage_name,
                'status' => $line->status,
                'status_label' => $line->status_label,
                'is_overdue' => $line->is_overdue,
            ])->values()->all(),
            'total_amount' => $convert($total),
            'paid_amount' => $convert($paid),
            'unpaid_amount' => $convert($unpaid),
            'next_due_date_label' => $shipment->payment_due_date?->format('d.m.Y'),
            'is_overdue' => $shipment->is_payment_overdue,
            // Арифметику документа ведёт 1С: расхождение показываем, но не «чиним».
            'mismatches_document' => abs($total - $documentTotal) > ShipmentPaymentSchedule::EPSILON,
            'document_total' => $convert($documentTotal),
        ];
    }
}
