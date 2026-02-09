<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\RelationFilterField;

class ModelFilterField extends RelationFilterField
{
    public function key(): string { return 'model_id'; }
    public function name(): string { return 'Группа'; }
    public function group(): string { return 'Связи'; }
    public function searchUrl(): ?string { return '/admin/product-models/search'; }
    protected function filterMode(): string { return 'direct'; }
    protected function column(): string { return 'model_id'; }
}
