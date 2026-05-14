<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CategoryIdField extends ExportField
{
    public function key(): string
    {
        return 'category.id';
    }

    public function name(): string
    {
        return 'ID категории';
    }

    public function description(): string
    {
        return 'Числовой идентификатор категории товара';
    }

    public function group(): string
    {
        return 'Категории';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function eagerLoad(): array
    {
        return ['category'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->category?->id;
    }
}
