<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CategoriesNameField extends ExportField
{
    public function key(): string { return 'categories.name'; }
    public function name(): string { return 'Категории (имена)'; }
    public function description(): string { return 'Названия всех категорий товара через запятую'; }
    public function group(): string { return 'Категории'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['categories']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->categories->pluck('name')->implode(', ');
    }
}
