<?php

namespace App\Services\Settlements;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;

/**
 * Единственный писатель производных данных регистра взаиморасчётов (v16.0.0).
 *
 * Делает две вещи: доклеивает движения к документам сайта и проецирует плановые
 * строки регистра в денормализованные колонки оплаты — `shipments.paid_amount /
 * payment_status / payment_due_date` и `orders.prepaid_amount` (волна 3, fin-11).
 * Колонки оставлены ради читателей: индексированный фильтр «Оплата» в кабинете,
 * внешний клиентский API и карточки во всех трёх панелях. Прежний писатель
 * (`PaymentAllocationService`) снесён — расшифровку платежей 1С не шлёт с v16.0.0.
 *
 * ## Почему связь мягкая
 *
 * Движение и документ приезжают разными очередями, у каждой свой connection
 * и свой набор воркеров. Порядок не гарантирован ни в какую сторону, поэтому
 * связь доклеивается с обеих:
 *
 *  - движение приехало после документа — сшивается здесь, при обработке движения;
 *  - движение приехало раньше — сшивается при создании документа
 *    (`SettlementLinkObserver`).
 *
 * До сшивки строка полностью читаема: `document_kind`, `document_number`
 * и `document_date` продублированы в неё намеренно. Документа может не быть
 * и вовсе — отчёт комиссионера на сайт не приезжает.
 */
class SettlementProjector
{
    /**
     * Классы документов сайта, к которым может относиться движение.
     *
     * Порядок значим: UUID глобально уникален, но проверка идёт по частоте
     * встречаемости — реализаций 106 тысяч, платежей 30 тысяч.
     *
     * @var list<class-string<Model>>
     */
    private const DOCUMENT_MODELS = [
        Shipment::class,
        Order::class,
        Payment::class,
    ];

    /**
     * Пересчитать всё производное по одному документу-регистратору.
     */
    public function projectDocument(string $documentUuid): void
    {
        $document = $this->findDocument($documentUuid);

        if (! $document) {
            return;
        }

        $this->linkTo($documentUuid, $document);

        if ($document instanceof Shipment) {
            $this->projectShipment($document);
        } elseif ($document instanceof Order) {
            $this->projectOrder($document);
        }
    }

    /**
     * Проекция оплаты реализации из плановых строк регистра.
     *
     * «Оплачено» — сумма построчных `min(amount, settled_amount)`: переплата
     * одной строки не гасит долг другой (та же арифметика, что в итогах журнала
     * реализаций — `LedgerPaymentForecastService::shipmentPaymentTotals()`).
     *
     * Документ без плановых строк не трогается: у регистра нет мнения о нём,
     * а обнуление показало бы «не оплачена» там, где прежние данные хоть что-то
     * отражали. Таких реализаций на проде 75 — вопрос о них задан 1С (топик №4).
     */
    public function projectShipment(Shipment $shipment): void
    {
        $lines = SettlementEntry::query()
            ->plans()
            ->where('document_uuid', $shipment->uuid)
            ->get(['amount', 'settled_amount', 'date']);

        if ($lines->isEmpty()) {
            return;
        }

        $paid = 0.0;
        $settledTotal = 0.0;
        $outstanding = 0.0;
        $dueDate = null;

        foreach ($lines as $line) {
            $amount = (float) $line->amount;
            $settled = (float) $line->settled_amount;

            $paid += min($amount, $settled);
            $settledTotal += $settled;
            $rest = $amount - $settled;

            if ($rest > SettlementEntry::EPSILON) {
                $outstanding += $rest;
                $lineDate = $line->date?->toDateString();

                if ($lineDate !== null && ($dueDate === null || $lineDate < $dueDate)) {
                    $dueDate = $lineDate;
                }
            }
        }

        $planTotal = (float) $lines->sum('amount');

        $status = match (true) {
            (float) $shipment->total_amount <= SettlementEntry::EPSILON => Shipment::PAYMENT_PAID,
            $outstanding <= SettlementEntry::EPSILON && $settledTotal > $planTotal + SettlementEntry::EPSILON => Shipment::PAYMENT_OVERPAID,
            $outstanding <= SettlementEntry::EPSILON => Shipment::PAYMENT_PAID,
            $settledTotal > SettlementEntry::EPSILON => Shipment::PAYMENT_PARTIAL,
            default => Shipment::PAYMENT_UNPAID,
        };

        $changes = [
            'paid_amount' => round($paid, 2),
            'payment_status' => $status,
            'payment_due_date' => $dueDate,
        ];

        if (! $this->dirty($shipment, $changes)) {
            return;
        }

        // withoutEvents + saveQuietly: проекция не повод дёргать Scout
        // и обсерверы — при бэкфиле это тысячи лишних переиндексаций.
        Shipment::withoutEvents(function () use ($shipment, $changes): void {
            $shipment->forceFill($changes)->saveQuietly();
        });
    }

    /**
     * Проекция предоплаты заказа.
     *
     * `document_settled_amount` — авторитетная сумма 1С на документ, одинаковая
     * во всех его строках; деление по этапам (`settled_amount`) — производное
     * для календаря, поэтому строчная сумма — только fallback.
     */
    public function projectOrder(Order $order): void
    {
        $lines = SettlementEntry::query()
            ->plans()
            ->where('document_uuid', $order->uuid)
            ->get(['settled_amount', 'document_settled_amount']);

        if ($lines->isEmpty()) {
            return;
        }

        $documentSettled = $lines->first(
            fn (SettlementEntry $line): bool => $line->document_settled_amount !== null,
        )?->document_settled_amount;

        $prepaid = round(max(0.0, (float) ($documentSettled ?? $lines->sum('settled_amount'))), 2);

        if (! $this->dirty($order, ['prepaid_amount' => $prepaid])) {
            return;
        }

        Order::withoutEvents(function () use ($order, $prepaid): void {
            $order->forceFill(['prepaid_amount' => $prepaid])->saveQuietly();
        });
    }

    /**
     * Изменилась ли проекция против сохранённого. Сравнение с допуском:
     * decimal приезжает строкой, и «10.00 !== 10.0» дал бы вечный UPDATE.
     *
     * @param  array<string, mixed>  $changes
     */
    private function dirty(Model $document, array $changes): bool
    {
        foreach ($changes as $attribute => $value) {
            $current = $document->getAttribute($attribute);

            if (is_float($value)) {
                if (abs($value - (float) $current) > 0.001) {
                    return true;
                }

                continue;
            }

            $current = $current instanceof \Carbon\CarbonInterface ? $current->toDateString() : $current;

            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проставить полиморфную связь движениям документа.
     *
     * Возвращает число сшитых строк — по нему видно, была ли доклейка полезной.
     */
    public function linkDocument(string $documentUuid): int
    {
        $document = $this->findDocument($documentUuid);

        if (! $document) {
            return 0;
        }

        return $this->linkTo($documentUuid, $document);
    }

    /**
     * Есть ли на сайте документ с таким UUID — для прогона «без записи»
     * в `settlements:relink-documents`.
     */
    public function hasDocument(string $documentUuid): bool
    {
        return $this->findDocument($documentUuid) !== null;
    }

    /**
     * Доклейка со стороны документа: он приехал позже своих движений.
     */
    public function linkToDocument(Model $document): int
    {
        $uuid = $document->getAttribute('uuid');

        if (! is_string($uuid) || $uuid === '') {
            return 0;
        }

        return $this->linkTo($uuid, $document);
    }

    private function linkTo(string $documentUuid, Model $document): int
    {
        return SettlementEntry::query()
            ->where('document_uuid', $documentUuid)
            ->where(function ($query) use ($document) {
                $query->whereNull('document_id')
                    ->orWhere('document_id', '!=', $document->getKey())
                    ->orWhere('document_type', '!=', $document->getMorphClass());
            })
            ->update([
                'document_type' => $document->getMorphClass(),
                'document_id' => $document->getKey(),
            ]);
    }

    /**
     * Документ сайта по UUID из 1С. `withTrashed` обязателен: движения остаются
     * и у мягко удалённого документа — регистр отражает историю, а не текущее
     * состояние справочников.
     */
    private function findDocument(string $documentUuid): ?Model
    {
        foreach (self::DOCUMENT_MODELS as $model) {
            /** @var Model|null $document */
            $document = $model::query()->withoutGlobalScopes()->withTrashed()
                ->where('uuid', $documentUuid)
                ->first();

            if ($document) {
                return $document;
            }
        }

        return null;
    }
}
