<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;
use App\Models\Product;
use App\Models\User;

class DescriptionPlainField extends ProductColumnField
{
    public function key(): string { return 'description_plain'; }
    public function name(): string { return 'Описание (без тегов)'; }
    public function description(): string { return 'Полное описание товара без HTML-тегов'; }
    public function group(): string { return 'Основные'; }
    public function isFilterable(): bool { return false; }
    protected function column(): string { return 'description'; }
    protected function columnType(): string { return 'text'; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return strip_tags($product->description ?? '');
    }
}
