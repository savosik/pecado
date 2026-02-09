<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class WarehousesNameField extends ExportField
{
    public function key(): string { return 'warehouses.name'; }
    public function name(): string { return 'Склады (имена)'; }
    public function description(): string { return 'Названия складов, на которых есть товар'; }
    public function group(): string { return 'Складские остатки'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['warehouses']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->warehouses->pluck('name')->implode(', ');
    }
}
