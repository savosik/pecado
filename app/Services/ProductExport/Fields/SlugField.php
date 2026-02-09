<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class SlugField extends ProductColumnField
{
    public function key(): string { return 'slug'; }
    public function name(): string { return 'URL-slug'; }
    public function description(): string { return 'Человекопонятный URL товара (ЧПУ)'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'slug'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
