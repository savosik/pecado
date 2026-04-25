<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class WidthField extends ProductColumnField
{
    public function key(): string
    {
        return 'width';
    }

    public function name(): string
    {
        return 'Ширина, м';
    }

    public function description(): string
    {
        return 'Ширина упаковки товара в метрах';
    }

    public function group(): string
    {
        return 'Габариты и вес';
    }

    protected function column(): string
    {
        return 'width';
    }

    protected function columnType(): string
    {
        return 'numeric';
    }
}
