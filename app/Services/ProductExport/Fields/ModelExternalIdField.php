<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class ModelExternalIdField extends ExportField
{
    public function key(): string
    {
        return 'model.external_id';
    }

    public function name(): string
    {
        return 'Модель (внешний ID)';
    }

    public function description(): string
    {
        return 'UUID модели из 1С / внешней системы';
    }

    public function group(): string
    {
        return 'Модель';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function eagerLoad(): array
    {
        return ['model'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->model?->external_id;
    }
}
