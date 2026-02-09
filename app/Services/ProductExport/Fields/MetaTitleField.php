<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class MetaTitleField extends ProductColumnField
{
    public function key(): string { return 'meta_title'; }
    public function name(): string { return 'Meta Title'; }
    public function description(): string { return 'SEO-заголовок страницы товара'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'meta_title'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
