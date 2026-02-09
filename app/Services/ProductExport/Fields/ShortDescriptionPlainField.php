<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;
use App\Models\Product;
use App\Models\User;

class ShortDescriptionPlainField extends ProductColumnField
{
    public function key(): string { return 'short_description_plain'; }
    public function name(): string { return 'Краткое описание (без тегов)'; }
    public function description(): string { return 'Краткое описание товара без HTML-тегов'; }
    public function group(): string { return 'Основные'; }
    public function isFilterable(): bool { return false; }
    protected function column(): string { return 'short_description'; }
    protected function columnType(): string { return 'text'; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return strip_tags($product->short_description ?? '');
    }
}
