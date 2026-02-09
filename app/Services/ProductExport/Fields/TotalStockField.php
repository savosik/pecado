<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class TotalStockField extends ExportField
{
    public function key(): string { return 'total_stock'; }
    public function name(): string { return 'Суммарный остаток'; }
    public function description(): string { return 'Суммарный остаток товара на всех складах'; }
    public function group(): string { return 'Складские остатки'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['warehouses']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->warehouses->sum('pivot.quantity');
    }
}
