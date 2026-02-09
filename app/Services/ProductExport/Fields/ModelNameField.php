<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class ModelNameField extends ExportField
{
    public function key(): string { return 'model.name'; }
    public function name(): string { return 'Модель (название)'; }
    public function description(): string { return 'Название модели товара'; }
    public function group(): string { return 'Модель'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['model']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->model?->name;
    }
}
