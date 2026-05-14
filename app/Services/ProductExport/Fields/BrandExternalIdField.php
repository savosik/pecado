<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class BrandExternalIdField extends ExportField
{
    public function key(): string
    {
        return 'brand.external_id';
    }

    public function name(): string
    {
        return 'Внешний ID бренда';
    }

    public function description(): string
    {
        return 'UUID бренда из 1С / внешней системы';
    }

    public function group(): string
    {
        return 'Бренд';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function eagerLoad(): array
    {
        return ['brand'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->brand?->external_id;
    }
}
