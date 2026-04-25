<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class DepthField extends ProductColumnField
{
    public function key(): string
    {
        return 'depth';
    }

    public function name(): string
    {
        return 'Глубина, м';
    }

    public function description(): string
    {
        return 'Глубина упаковки товара в метрах';
    }

    public function group(): string
    {
        return 'Габариты и вес';
    }

    protected function column(): string
    {
        return 'depth';
    }

    protected function columnType(): string
    {
        return 'numeric';
    }
}
