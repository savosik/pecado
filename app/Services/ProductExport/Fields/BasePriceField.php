<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class BasePriceField extends ProductColumnField
{
    public function key(): string { return 'base_price'; }
    public function name(): string { return 'Базовая цена'; }
    public function description(): string { return 'Базовая розничная цена товара без скидок'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'base_price'; }
    protected function columnType(): string { return 'numeric'; }
    protected function isPriceField(): bool { return true; }
}
