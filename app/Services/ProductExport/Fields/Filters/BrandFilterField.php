<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\RelationFilterField;

class BrandFilterField extends RelationFilterField
{
    public function key(): string { return 'brand_id'; }
    public function name(): string { return 'Бренд'; }
    public function group(): string { return 'Связи'; }
    public function searchUrl(): ?string { return '/admin/brands/search'; }
    protected function filterMode(): string { return 'direct'; }
    protected function column(): string { return 'brand_id'; }
}
