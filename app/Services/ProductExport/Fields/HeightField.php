<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class HeightField extends ProductColumnField
{
    public function key(): string
    {
        return 'height';
    }

    public function name(): string
    {
        return 'Высота, м';
    }

    public function description(): string
    {
        return 'Высота упаковки товара в метрах';
    }

    public function group(): string
    {
        return 'Габариты и вес';
    }

    protected function column(): string
    {
        return 'height';
    }

    protected function columnType(): string
    {
        return 'numeric';
    }
}
