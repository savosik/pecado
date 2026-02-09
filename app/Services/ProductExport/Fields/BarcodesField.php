<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class BarcodesField extends ExportField
{
    public function key(): string { return 'barcodes.barcode'; }
    public function name(): string { return 'Все штрихкоды'; }
    public function description(): string { return 'Все штрихкоды товара через запятую'; }
    public function group(): string { return 'Штрихкоды'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['barcodes']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->barcodes->pluck('barcode')->implode(', ');
    }
}
