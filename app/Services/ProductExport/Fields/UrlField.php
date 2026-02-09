<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class UrlField extends ProductColumnField
{
    public function key(): string { return 'url'; }
    public function name(): string { return 'URL'; }
    public function description(): string { return 'Полный URL страницы товара на сайте'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'url'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
