<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\ProductExport\ExportField;

/**
 * Свод всех атрибутов товара одной колонкой/массивом.
 *
 * CSV/XLS: строка вида "Имя атрибута:значение\nИмя атрибута:значение\n..." —
 * совместимо с форматом sex-opt «Все характеристики товара».
 * JSON/XML: массив объектов {name, value} — структурно для парсера.
 *
 * Skip-логика: пропускаем атрибуты с is_partner_only=true (партнёрские
 * атрибуты vakulov'а), и пропускаем атрибут-значения с пустым значением.
 */
class AllAttributesField extends ExportField
{
    public function key(): string
    {
        return 'all_attributes';
    }

    public function name(): string
    {
        return 'Все характеристики товара';
    }

    public function description(): string
    {
        return 'Все атрибуты товара в одной колонке в формате «Имя:значение» через перенос строки';
    }

    public function group(): string
    {
        return 'Атрибуты';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function modifierType(): ?string
    {
        return 'multi_value';
    }

    public function eagerLoad(): array
    {
        return ['attributeValues.attribute', 'attributeValues.attributeValue'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $pairs = $this->collectPairs($product);
        if (empty($pairs)) {
            return null;
        }

        return implode("\n", array_map(
            fn ($p) => $p['name'].':'.$p['value'],
            $pairs
        ));
    }

    public function nativeValue(Product $product, ?User $clientUser = null): mixed
    {
        $pairs = $this->collectPairs($product);

        return empty($pairs) ? null : $pairs;
    }

    /**
     * @return array<int, array{name:string, value:string}>
     */
    protected function collectPairs(Product $product): array
    {
        $pairs = [];
        foreach ($product->attributeValues as $av) {
            $attribute = $av->attribute;
            if (! $attribute || ! empty($attribute->is_partner_only)) {
                continue;
            }
            $value = $this->extractValue($av);
            if ($value === null || $value === '') {
                continue;
            }
            $name = $attribute->name;
            if (! empty($attribute->unit)) {
                $name .= ', '.$attribute->unit;
            }
            $pairs[] = ['name' => $name, 'value' => (string) $value];
        }

        return $pairs;
    }

    protected function extractValue(ProductAttributeValue $av): mixed
    {
        $attr = $av->attribute;
        if (! $attr) {
            return null;
        }
        if ($attr->isBoolean()) {
            return $av->boolean_value !== null
                ? ((bool) $av->boolean_value ? 'Да' : 'Нет')
                : null;
        }
        if ($attr->isNumber()) {
            return $av->number_value;
        }
        if ($av->attribute_value_id) {
            return $av->attributeValue?->value ?? $av->attribute_value_id;
        }
        if ($av->text_value !== null && $av->text_value !== '') {
            return $av->text_value;
        }
        if ($av->number_value !== null) {
            return $av->number_value;
        }

        return null;
    }
}
