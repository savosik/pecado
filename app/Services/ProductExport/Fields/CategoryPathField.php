<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class CategoryPathField extends ExportField
{
    public function key(): string
    {
        return 'category_path';
    }

    public function name(): string
    {
        return 'Путь категории';
    }

    public function description(): string
    {
        return 'Полный путь категории (Одежда / Верхняя / Куртки)';
    }

    public function group(): string
    {
        return 'Категории';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    /**
     * Поддерживает multi_value-модификатор: клиент может указать
     * `source_separator=slash` (входной разделитель — слеш с пробелами,
     * как мы пишем по умолчанию) и любой целевой `separator` —
     * например `slash_tight` чтобы получить "А/Б/В" без пробелов.
     */
    public function modifierType(): ?string
    {
        return 'multi_value';
    }

    public function eagerLoad(): array
    {
        return ['category.ancestors'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $category = $product->category;
        if (! $category) {
            return null;
        }

        $ancestors = $category->ancestors->pluck('name')->toArray();
        $ancestors[] = $category->name;

        return implode(' / ', $ancestors);
    }
}
