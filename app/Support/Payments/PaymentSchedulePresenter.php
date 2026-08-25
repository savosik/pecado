<?php

namespace App\Support\Payments;

use App\Models\SettlementEntry;
use App\Models\Shipment;

/**
 * Блок «График оплаты» в карточке реализации.
 *
 * Один презентер на четыре интерфейса (кабинет, CRM, админка, клиентское API):
 * график везде показывается одинаково — он read-only и мастером является 1С.
 * Расходится только валюта: кабинет пересчитывает суммы в валюту клиента,
 * сотрудники видят их как в документе, поэтому пересчёт передаётся колбэком.
 *
 * Источник — плановые строки регистра взаиморасчётов (fin-11). Прежняя таблица
 * `shipment_payment_schedules` не наполняется с 12.08.2026: 1С присылает график
 * событием `payment_schedule.updated`, и карточки реализаций, отгруженных после
 * этой даты, показывали пустой блок. Форма ответа сохранена в точности —
 * общий фронт-компонент PaymentScheduleBlock и внешний API её знают.
 */
class PaymentSchedulePresenter
{
    /**
     * @param  (callable(float): float)|null  $convert  Пересчёт суммы в валюту показа
     * @return array<string, mixed>|null null — 1С не присылала график по этой реализации
     */
    public static function forShipment(Shipment $shipment, ?callable $convert = null): ?array
    {
        $lines = SettlementEntry::query()
            ->plans()
            ->where('document_uuid', $shipment->uuid)
            ->orderByRaw('COALESCE(line_number, 2147483647)')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $convert ??= static fn (float $amount): float => $amount;

        $total = round((float) $lines->sum('amount'), 2);
        // Построчный кламп: закрытая часть строки не может превысить её сумму,
        // иначе переплата по одной гасила бы долг по другой.
        $paid = round((float) $lines->sum(
            static fn (SettlementEntry $line): float => min((float) $line->amount, (float) $line->settled_amount),
        ), 2);
        $unpaid = round((float) $lines->sum(
            static fn (SettlementEntry $line): float => $line->unsettled_amount,
        ), 2);
        $documentTotal = round((float) $shipment->total_amount, 2);

        // Ближайший срок берётся из самих строк, а не из денормализованной
        // shipments.payment_due_date: карточка не должна зависеть от того,
        // успела ли отработать проекция.
        $nextDue = $lines
            ->filter(static fn (SettlementEntry $line): bool => $line->unsettled_amount > SettlementEntry::EPSILON)
            ->pluck('date')
            ->filter()
            ->sort()
            ->first();

        return [
            'lines' => $lines->map(fn (SettlementEntry $line): array => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'due_date' => $line->date?->toDateString(),
                'due_date_label' => $line->date?->format('d.m.Y'),
                'amount' => $convert((float) $line->amount),
                'paid_amount' => $convert(min((float) $line->amount, (float) $line->settled_amount)),
                'unpaid_amount' => $convert($line->unsettled_amount),
                // Реквизиты этапа 1С отдаёт «только для показа» — они лежат
                // в meta и в расчётах не участвуют.
                'percent' => isset($line->meta['percent']) ? (float) $line->meta['percent'] : null,
                'term_days' => $line->meta['term_days'] ?? null,
                'basis_name' => $line->meta['basis_name'] ?? null,
                'stage_name' => $line->meta['stage_name'] ?? null,
                'status' => $line->status,
                'status_label' => $line->status_label,
                'is_overdue' => $line->is_overdue,
            ])->values()->all(),
            'total_amount' => $convert($total),
            'paid_amount' => $convert($paid),
            'unpaid_amount' => $convert($unpaid),
            'next_due_date_label' => $nextDue?->format('d.m.Y'),
            'is_overdue' => $lines->contains(static fn (SettlementEntry $line): bool => $line->is_overdue),
            // Арифметику документа ведёт 1С: расхождение показываем, но не «чиним».
            'mismatches_document' => abs($total - $documentTotal) > SettlementEntry::EPSILON,
            'document_total' => $convert($documentTotal),
        ];
    }
}
