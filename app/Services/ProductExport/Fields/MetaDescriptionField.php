<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class MetaDescriptionField extends ProductColumnField
{
    public function key(): string { return 'meta_description'; }
    public function name(): string { return 'Meta Description'; }
    public function description(): string { return 'SEO-описание страницы товара'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'meta_description'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
