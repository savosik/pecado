<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class SizeChartNameField extends ExportField
{
    public function key(): string { return 'size_chart.name'; }
    public function name(): string { return 'Размерная сетка'; }
    public function description(): string { return 'Название размерной сетки товара'; }
    public function group(): string { return 'Размерная сетка'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['sizeChart']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->sizeChart?->name;
    }
}
