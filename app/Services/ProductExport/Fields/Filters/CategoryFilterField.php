<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\RelationFilterField;

class CategoryFilterField extends RelationFilterField
{
    public function key(): string { return 'category_id'; }
    public function name(): string { return 'Категория'; }
    public function group(): string { return 'Связи'; }
    public function searchUrl(): ?string { return '/admin/categories/search'; }
    protected function filterMode(): string { return 'direct'; }
    protected function column(): string { return 'category_id'; }
}
