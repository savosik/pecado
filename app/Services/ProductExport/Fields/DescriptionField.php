<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class DescriptionField extends ProductColumnField
{
    public function key(): string { return 'description'; }
    public function name(): string { return 'Описание'; }
    public function description(): string { return 'Полное описание товара с HTML-разметкой'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'description'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
