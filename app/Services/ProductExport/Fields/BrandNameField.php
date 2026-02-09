<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class BrandNameField extends ExportField
{
    public function key(): string { return 'brand.name'; }
    public function name(): string { return 'Название бренда'; }
    public function description(): string { return 'Название бренда товара'; }
    public function group(): string { return 'Бренд'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['brand']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->brand?->name;
    }
}
