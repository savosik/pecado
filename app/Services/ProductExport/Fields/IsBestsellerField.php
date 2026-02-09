<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class IsBestsellerField extends ProductColumnField
{
    public function key(): string { return 'is_bestseller'; }
    public function name(): string { return 'Бестселлер'; }
    public function description(): string { return 'Флаг «Бестселлер» для популярных товаров'; }
    public function group(): string { return 'Флаги'; }
    protected function column(): string { return 'is_bestseller'; }
    protected function columnType(): string { return 'boolean'; }
}
