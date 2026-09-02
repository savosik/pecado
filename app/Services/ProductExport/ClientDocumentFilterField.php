<?php

namespace App\Services\ProductExport;

use Illuminate\Database\Eloquent\Builder;

/**
 * Базовый класс для фильтров «товар содержится в документах клиента»
 * (заказы, реализации). Значение — массив id выбранных документов.
 *
 * Владение документом определяется как в кабинете: {documents}.user_id =
 * client_user_id выгрузки. Даже если в запросе окажутся чужие id (руками
 * подставили в JSON), условие сузит их до документов клиента — состав
 * чужого заказа через выгрузку не утечёт.
 */
abstract class ClientDocumentFilterField extends ExportField
{
    public function isExportable(): bool
    {
        return false; // Только для фильтрации
    }

    public function filterType(): ?string
    {
        return 'relation';
    }

    public function operators(): array
    {
        return ['in', 'not_in'];
    }

    public function group(): string
    {
        return 'Покупки';
    }

    /**
     * Связь Product → строки документа ('orderItems' | 'shipmentItems')
     */
    abstract protected function itemsRelation(): string;

    /**
     * FK строки на документ ('order_id' | 'shipment_id')
     */
    abstract protected function documentColumn(): string;

    /**
     * Связь строки документа → сам документ ('order' | 'shipment')
     */
    abstract protected function documentRelation(): string;

    public function applyFilter(Builder $query, string $operator, mixed $value, ?int $clientUserId = null): void
    {
        $ids = array_values(array_filter(
            array_map('intval', is_array($value) ? $value : [$value]),
        ));

        if (empty($ids)) {
            return;
        }

        $constraint = function ($q) use ($ids, $clientUserId) {
            $q->whereIn($this->documentColumn(), $ids);

            if ($clientUserId !== null) {
                $q->whereHas($this->documentRelation(), fn ($doc) => $doc->where('user_id', $clientUserId));
            }
        };

        if ($operator === 'not_in') {
            $query->whereDoesntHave($this->itemsRelation(), $constraint);
        } else {
            $query->whereHas($this->itemsRelation(), $constraint);
        }
    }
}
