<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class WeightGrossField extends ProductColumnField
{
    public function key(): string
    {
        return 'weight_gross';
    }

    public function name(): string
    {
        return 'Вес брутто, кг';
    }

    public function description(): string
    {
        return 'Вес товара с упаковкой в килограммах';
    }

    public function group(): string
    {
        return 'Габариты и вес';
    }

    protected function column(): string
    {
        return 'weight_gross';
    }

    protected function columnType(): string
    {
        return 'numeric';
    }
}
