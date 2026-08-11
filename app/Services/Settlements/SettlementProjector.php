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
 * Сейчас делает ровно одно — доклеивает движения к документам сайта. Полноценные
 * проекции (`shipments.paid_amount`, `orders.prepaid_amount`) появятся в волне 3
 * вместе с флагом `SETTLEMENTS_LEDGER_ENABLED`. Раньше их трогать нельзя: эти
 * колонки пока пишет `PaymentAllocationService`, и два писателя подерутся —
 * результат зависел бы от того, чьё сообщение доехало последним.
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
        $this->linkDocument($documentUuid);
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
