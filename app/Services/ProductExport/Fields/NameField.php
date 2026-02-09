<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class NameField extends ProductColumnField
{
    public function key(): string { return 'name'; }
    public function name(): string { return 'Наименование'; }
    public function description(): string { return 'Наименование товара'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'name'; }
    protected function columnType(): string { return 'text'; }
}
