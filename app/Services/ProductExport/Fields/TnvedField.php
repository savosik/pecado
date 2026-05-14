<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class TnvedField extends ProductColumnField
{
    public function key(): string
    {
        return 'tnved';
    }

    public function name(): string
    {
        return 'Код ТН ВЭД';
    }

    public function description(): string
    {
        return 'Код ТН ВЭД для таможенного оформления (хранится в products.hs_code).';
    }

    public function group(): string
    {
        return 'Основные';
    }

    /**
     * В БД код ТН ВЭД хранится в колонке `hs_code` (Harmonised System code),
     * добавленной миграцией `2026_04_25_120000_add_dimensions_and_classification_to_products_table`.
     * Старое имя `tnved` оставлено только в ключе поля для обратной совместимости
     * с уже сохранёнными конфигами выгрузок.
     */
    protected function column(): string
    {
        return 'hs_code';
    }

    protected function columnType(): string
    {
        return 'text';
    }

    public function isFilterable(): bool
    {
        return false;
    }
}
