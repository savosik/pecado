<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CategoriesNameField extends ExportField
{
    public function key(): string { return 'category.name'; }
    public function name(): string { return 'Категория'; }
    public function description(): string { return 'Название категории товара'; }
    public function group(): string { return 'Категории'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return null; }
    public function eagerLoad(): array { return ['category']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->category?->name;
    }
}
