<?php

namespace App\Services\Erp\Support;

/**
 * Счёт-фактура реализации из 1С (протокол v15.16.0).
 *
 * До v15.16.0 места под неё в контракте не было: все вхождения слова `invoice`
 * в спецификации относились к значению `invoice_date` перечисления `basis`
 * графика оплаты — то есть к дате счёта на оплату, а не к счёту-фактуре.
 * 1С при этом поле уже присылала, а данные нужны бухгалтерии клиента.
 *
 * Общий трейт для shipment.created и shipment.updated: расхождение маппинга
 * между ними означало бы, что перепроведение документа теряет реквизит.
 */
trait ReadsShipmentInvoice
{
    /**
     * Поля счёта-фактуры для записи в реализацию.
     *
     * Отсутствие ключа `invoice` не сбрасывает сохранённое: 1С может не уметь
     * присылать его по всем документам сразу. Явный `null` в самом объекте —
     * операция, и она обнуляет реквизит.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function invoiceFields(array $payload): array
    {
        if (! array_key_exists('invoice', $payload)) {
            return [];
        }

        $invoice = $payload['invoice'];

        if (! is_array($invoice)) {
            return [
                'invoice_number' => null,
                'invoice_number_display' => null,
                'invoice_date' => null,
            ];
        }

        return [
            // number — внутренний номер 1С («29УТ-0006968»), для сверки с базой;
            // number_display — печатный («УТ-6968»), его видит клиент (v15.16.1)
            'invoice_number' => $invoice['number'] ?? null,
            'invoice_number_display' => $invoice['number_display'] ?? null,
            'invoice_date' => $invoice['date'] ?? null,
        ];
    }
}
