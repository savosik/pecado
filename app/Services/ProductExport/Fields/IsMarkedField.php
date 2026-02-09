<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class IsMarkedField extends ProductColumnField
{
    public function key(): string { return 'is_marked'; }
    public function name(): string { return 'Маркированный товар'; }
    public function description(): string { return 'Флаг обязательной маркировки товара'; }
    public function group(): string { return 'Флаги'; }
    protected function column(): string { return 'is_marked'; }
    protected function columnType(): string { return 'boolean'; }
}
