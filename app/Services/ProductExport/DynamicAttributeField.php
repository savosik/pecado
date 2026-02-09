<?php

namespace App\Services\ProductExport;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Динамическое поле на основе атрибута из БД.
 *
 * Один экземпляр создаётся на каждый Attribute. Поведение фильтрации и
 * извлечения значения зависит от type атрибута (string, number, boolean, select).
 */
class DynamicAttributeField extends ExportField
{
    protected Attribute $attribute;

    public function __construct(Attribute $attribute)
    {
        $this->attribute = $attribute;
    }

    // ─── Identification ────────────────────────────

    /**
     * Ключ для фильтров: attr.{id}
     * Ключ для экспорта: attribute.{id}
     */
    public function key(): string
    {
        return "attr.{$this->attribute->id}";
    }

    public function exportKey(): string
    {
        return "attribute.{$this->attribute->id}";
    }

    public function name(): string
    {
        $label = $this->attribute->name;
        if ($this->attribute->unit) {
            $label .= " ({$this->attribute->unit})";
        }
        return $label;
    }

    public function group(): string
    {
        return $this->attribute->attributeGroup?->name ?? 'Атрибуты';
    }

    // ─── Type mapping ──────────────────────────────

    public function filterType(): ?string
    {
        return match ($this->attribute->type) {
            'boolean' => 'boolean',
            'number' => 'numeric',
            'select' => 'select',
            default => 'text', // string
        };
    }

    public function operators(): array
    {
        return match ($this->attribute->type) {
            'boolean' => ['='],
            'number' => ['=', '>', '<', '>=', '<=', 'between'],
            'select' => ['in', 'not_in'],
            default => ['=', 'contains', 'not_contains', 'starts_with'],
        };
    }

    public function options(): array
    {
        if ($this->attribute->type !== 'select') {
            return [];
        }

        return $this->attribute->values->map(fn ($v) => [
            'value' => $v->id,
            'label' => $v->value,
        ])->toArray();
    }

    public function eagerLoad(): array
    {
        return ['attributeValues.attribute'];
    }

    // ─── Filter Meta ───────────────────────────

    public function filterMeta(): array
    {
        return [
            'attribute_id' => $this->attribute->id,
            'attribute_type' => $this->attribute->type,
        ];
    }

    // ─── Filter Logic ──────────────────────────────

    public function applyFilter(Builder $query, string $operator, mixed $value): void
    {
        $attribute = $this->attribute;

        $query->whereHas('attributeValues', function ($q) use ($attribute, $operator, $value) {
            $q->where('attribute_id', $attribute->id);

            if ($value === null || $value === '') {
                return;
            }

            match ($attribute->type) {
                'select' => $this->applySelectFilter($q, $operator, $value),
                'number' => $this->applyNumberFilter($q, $operator, $value),
                'boolean' => $q->where('boolean_value', (bool) $value),
                default => $this->applyStringFilter($q, $operator, $value),
            };
        });
    }

    protected function applySelectFilter($q, string $operator, mixed $value): void
    {
        $ids = is_array($value) ? $value : [$value];
        if ($operator === 'not_in') {
            $q->whereNotIn('attribute_value_id', $ids);
        } else {
            $q->whereIn('attribute_value_id', $ids);
        }
    }

    protected function applyNumberFilter($q, string $operator, mixed $value): void
    {
        if ($operator === 'between' && is_array($value) && count($value) === 2) {
            $q->whereBetween('number_value', [$value[0], $value[1]]);
        } else {
            $q->where('number_value', $operator, $value);
        }
    }

    protected function applyStringFilter($q, string $operator, mixed $value): void
    {
        match ($operator) {
            '=' => $q->where('text_value', $value),
            'contains' => $q->where('text_value', 'like', "%{$value}%"),
            'not_contains' => $q->where('text_value', 'not like', "%{$value}%"),
            'starts_with' => $q->where('text_value', 'like', "{$value}%"),
            default => null,
        };
    }

    // ─── Export Value ──────────────────────────────

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $attrValue = $product->attributeValues->firstWhere('attribute_id', $this->attribute->id);

        if (!$attrValue) {
            return null;
        }

        if ($attrValue->text_value !== null) {
            return $attrValue->text_value;
        }
        if ($attrValue->number_value !== null) {
            return $attrValue->number_value;
        }
        if ($attrValue->boolean_value !== null) {
            return (bool) $attrValue->boolean_value ? 'Да' : 'Нет';
        }
        if ($attrValue->attribute_value_id) {
            return $attrValue->attributeValue?->value ?? $attrValue->attribute_value_id;
        }

        return null;
    }

    // ─── Serialization overrides ──────────────────

    /**
     * Для фильтров используем ключ attr.{id}
     */
    public function toFilterDefinition(): array
    {
        return parent::toFilterDefinition();
    }

    /**
     * Для экспортных полей используем ключ attribute.{id}
     */
    public function toFieldDefinition(): array
    {
        return [
            'key' => $this->exportKey(),
            'label' => $this->name(),
        ];
    }
}
