<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CategoryPathField extends ExportField
{
    public function key(): string { return 'category_path'; }
    public function name(): string { return 'Путь категорий'; }
    public function description(): string { return 'Полный путь категории (Одежда / Верхняя / Куртки)'; }
    public function group(): string { return 'Категории'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['categories.ancestors']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->categories->map(function ($category) {
            $ancestors = $category->ancestors->pluck('name')->toArray();
            $ancestors[] = $category->name;
            return implode(' / ', $ancestors);
        })->implode(', ');
    }
}
