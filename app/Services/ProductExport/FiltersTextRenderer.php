<?php

namespace App\Services\ProductExport;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\ProductModel;
use App\Models\Warehouse;

/**
 * Рендерит структуру `ProductExport.filters` (JSON) в плоскую строку имён
 * связанных сущностей (бренды, категории, модели, склады, сертификаты),
 * пригодную для LIKE-поиска по `product_exports.filters_text`.
 */
class FiltersTextRenderer
{
    /**
     * @param  array<mixed>  $filters
     */
    public function render(array $filters): string
    {
        if (empty($filters)) {
            return '';
        }

        $idsByField = [
            'brand_id' => [],
            'category_id' => [],
            'model_id' => [],
            'warehouse_id' => [],
            'certificate_id' => [],
        ];

        $this->collectIds($filters, $idsByField);

        $names = [];

        if (! empty($idsByField['brand_id'])) {
            $names = array_merge($names, Brand::query()
                ->whereIn('id', array_unique($idsByField['brand_id']))
                ->pluck('name')->all());
        }
        if (! empty($idsByField['category_id'])) {
            $names = array_merge($names, Category::query()
                ->whereIn('id', array_unique($idsByField['category_id']))
                ->pluck('name')->all());
        }
        if (! empty($idsByField['model_id'])) {
            $names = array_merge($names, ProductModel::query()
                ->whereIn('id', array_unique($idsByField['model_id']))
                ->pluck('name')->all());
        }
        if (! empty($idsByField['warehouse_id'])) {
            $names = array_merge($names, Warehouse::query()
                ->whereIn('id', array_unique($idsByField['warehouse_id']))
                ->pluck('name')->all());
        }
        if (! empty($idsByField['certificate_id'])) {
            $names = array_merge($names, Certificate::query()
                ->whereIn('id', array_unique($idsByField['certificate_id']))
                ->pluck('name')->all());
        }

        return trim(implode(' ', array_filter(array_map('strval', $names), fn ($n) => $n !== '')));
    }

    /**
     * @param  array<string, array<int>>  $idsByField
     */
    private function collectIds(array $filters, array &$idsByField): void
    {
        // Иерархический формат: {logic, conditions: [...]}
        if (isset($filters['conditions']) && is_array($filters['conditions'])) {
            foreach ($filters['conditions'] as $condition) {
                if (! is_array($condition)) {
                    continue;
                }

                $type = $condition['type'] ?? 'condition';
                if ($type === 'group') {
                    $this->collectIds($condition, $idsByField);
                } else {
                    $this->collectFromCondition($condition, $idsByField);
                }
            }

            return;
        }

        // Плоский формат: [{field, operator, value}, ...]
        foreach ($filters as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $this->collectFromCondition($condition, $idsByField);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, array<int>>  $idsByField
     */
    private function collectFromCondition(array $condition, array &$idsByField): void
    {
        $field = $condition['field'] ?? null;
        if (! is_string($field) || ! array_key_exists($field, $idsByField)) {
            return;
        }

        $value = $condition['value'] ?? null;
        $ids = is_array($value) ? $value : [$value];

        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $idsByField[$field][] = (int) $id;
            }
        }
    }
}
