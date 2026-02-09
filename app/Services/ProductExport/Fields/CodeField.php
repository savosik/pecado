<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class CodeField extends ProductColumnField
{
    public function key(): string { return 'code'; }
    public function name(): string { return 'Код товара'; }
    public function description(): string { return 'Код товара, уникальное значение'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'code'; }
    protected function columnType(): string { return 'text'; }
}
