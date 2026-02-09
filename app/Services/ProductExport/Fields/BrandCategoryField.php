<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class BrandCategoryField extends ExportField
{
    public function key(): string { return 'brand.category'; }
    public function name(): string { return 'Категория бренда'; }
    public function description(): string { return 'Категория бренда (сегмент)'; }
    public function group(): string { return 'Бренд'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['brand']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->brand?->category;
    }
}
