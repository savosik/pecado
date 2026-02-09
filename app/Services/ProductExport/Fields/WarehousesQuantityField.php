<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class WarehousesQuantityField extends ExportField
{
    public function key(): string { return 'warehouses.pivot.quantity'; }
    public function name(): string { return 'Остаток по складам'; }
    public function description(): string { return 'Количество товара на каждом складе'; }
    public function group(): string { return 'Складские остатки'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['warehouses']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->warehouses->map(fn ($w) => "{$w->name}: {$w->pivot->quantity}")->implode(', ');
    }
}
