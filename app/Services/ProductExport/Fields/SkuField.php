<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class SkuField extends ProductColumnField
{
    public function key(): string { return 'sku'; }
    public function name(): string { return 'Артикул'; }
    public function description(): string { return 'Артикул товара, может быть неуникальным'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'sku'; }
    protected function columnType(): string { return 'text'; }
}
