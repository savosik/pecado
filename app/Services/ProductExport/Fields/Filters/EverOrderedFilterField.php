<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\ExportField;
use Illuminate\Database\Eloquent\Builder;

/**
 * Фильтр «товар когда-либо заказывался клиентом» (да/нет).
 *
 * Кейс: клиент хочет выгрузить себе на сайт ровно тот перечень товаров,
 * которые он у нас заказывал. Учитывается сам факт позиции в заказе —
 * отменённые позиции не исключаются.
 */
class EverOrderedFilterField extends ExportField
{
    public function key(): string
    {
        return 'ever_ordered';
    }

    public function name(): string
    {
        return 'Когда-либо заказывался';
    }

    public function group(): string
    {
        return 'Покупки';
    }

    public function isExportable(): bool
    {
        return false; // Только для фильтрации
    }

    public function filterType(): ?string
    {
        return 'boolean';
    }

    public function operators(): array
    {
        return ['='];
    }

    public function applyFilter(Builder $query, string $operator, mixed $value, ?int $clientUserId = null): void
    {
        // Без клиента условие не имеет смысла (чьи заказы?) — не применяем,
        // как и незнакомые ключи в ProductExportService::applyFieldFilter.
        if ($clientUserId === null) {
            return;
        }

        $constraint = fn ($q) => $q->whereHas('order', fn ($o) => $o->where('user_id', $clientUserId));

        if ((bool) $value) {
            $query->whereHas('orderItems', $constraint);
        } else {
            $query->whereDoesntHave('orderItems', $constraint);
        }
    }
}
