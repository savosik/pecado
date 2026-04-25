<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class WeightNetField extends ProductColumnField
{
    public function key(): string
    {
        return 'weight_net';
    }

    public function name(): string
    {
        return 'Вес нетто, кг';
    }

    public function description(): string
    {
        return 'Вес товара без упаковки в килограммах';
    }

    public function group(): string
    {
        return 'Габариты и вес';
    }

    protected function column(): string
    {
        return 'weight_net';
    }

    protected function columnType(): string
    {
        return 'numeric';
    }
}
