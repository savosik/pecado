<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class ClientRegionField extends ExportField
{
    public function key(): string { return 'client_region'; }
    public function name(): string { return 'Регион клиента'; }
    public function description(): string { return 'Регион выбранного клиента'; }
    public function group(): string { return 'Пользовательские (по клиенту)'; }
    public function isFilterable(): bool { return false; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $clientUser?->region?->name ?? '—';
    }
}
