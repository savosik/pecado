<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CategoryExternalIdField extends ExportField
{
    public function key(): string
    {
        return 'category.external_id';
    }

    public function name(): string
    {
        return 'Внешний ID категории';
    }

    public function description(): string
    {
        return 'UUID категории из 1С / внешней системы';
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
        return $product->category?->external_id;
    }
}
