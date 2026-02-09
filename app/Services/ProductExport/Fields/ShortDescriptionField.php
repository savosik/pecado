<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class ShortDescriptionField extends ProductColumnField
{
    public function key(): string { return 'short_description'; }
    public function name(): string { return 'Краткое описание'; }
    public function description(): string { return 'Краткое описание товара с HTML-разметкой'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'short_description'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
