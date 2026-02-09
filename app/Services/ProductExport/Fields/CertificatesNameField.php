<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CertificatesNameField extends ExportField
{
    public function key(): string { return 'certificates.name'; }
    public function name(): string { return 'Сертификаты (имена)'; }
    public function description(): string { return 'Сертификаты товара через запятую'; }
    public function group(): string { return 'Сертификаты'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['certificates']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->certificates->pluck('name')->implode(', ');
    }
}
