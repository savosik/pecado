<?php

namespace App\Models\Traits;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-скоупы для каталога товаров.
 *
 * Подключается к модели Product. Используется в CatalogApiController
 * для построения фильтрованных/сортированных запросов.
 */
trait ProductQueryScopes
{
    /**
     * Только активные товары.
     * Заглушка: поле is_active пока не добавлено — без фильтра.
     */
    public function scopeActive(Builder $query): Builder
    {
        // TODO: раскомментировать, когда добавится поле is_active
        // return $query->where('is_active', true);
        return $query;
    }

    /**
     * Поиск по имени, SKU и штрих-коду.
     * Использует LIKE — case-insensitive в дефолтных _ci коллациях MySQL.
     */
    public function scopeSearch(Builder $query, string $q): Builder
    {
        $like = '%' . mb_strtolower($q) . '%';

        return $query->where(function (Builder $query) use ($like, $q) {
            $query->where('name', 'LIKE', $like)
                  ->orWhere('sku', 'LIKE', $like)
                  ->orWhere('barcode', '=', $q);
        });
    }

    /**
     * Фильтр по одной категории (с опциональным включением потомков).
     * Принимает Category или int. Использует _lft/_rgt из nested set (Kalnoy).
     */
    public function scopeInCategory(Builder $query, Category|int $category, bool $descendants = true): Builder
    {
        if (is_int($category)) {
            if (! $descendants) {
                return $query->where('category_id', $category);
            }

            $category = Category::find($category);

            if (! $category) {
                return $query->whereRaw('1 = 0');
            }
        }

        if (! $descendants) {
            return $query->where('category_id', $category->id);
        }

        return $query->whereIn('category_id', function ($sub) use ($category) {
            $sub->select('id')
                ->from('categories')
                ->where('_lft', '>=', $category->_lft)
                ->where('_rgt', '<=', $category->_rgt);
        });
    }

    /**
     * Фильтр по нескольким категориям (с опциональным включением потомков).
     */
    public function scopeInCategories(Builder $query, array $ids, bool $descendants = true): Builder
    {
        if (empty($ids)) {
            return $query;
        }

        if (! $descendants) {
            return $query->whereIn('category_id', $ids);
        }

        $categories = Category::whereIn('id', $ids)->get(['id', '_lft', '_rgt']);

        if ($categories->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('category_id', function ($sub) use ($categories) {
            $sub->select('id')->from('categories');

            $sub->where(function ($orGroup) use ($categories) {
                foreach ($categories as $cat) {
                    $orGroup->orWhere(function ($w) use ($cat) {
                        $w->where('_lft', '>=', $cat->_lft)
                          ->where('_rgt', '<=', $cat->_rgt);
                    });
                }
            });
        });
    }

    /**
     * Фильтр по брендам.
     */
    public function scopeInBrands(Builder $query, array $ids): Builder
    {
        if (empty($ids)) {
            return $query;
        }

        return $query->whereIn('brand_id', $ids);
    }

    /**
     * Фильтр по подборкам (коллекциям).
     */
    public function scopeInCollections(Builder $query, array $ids): Builder
    {
        if (empty($ids)) {
            return $query;
        }

        return $query->whereHas('productSelections', function (Builder $q) use ($ids) {
            $q->whereIn('product_selections.id', $ids);
        });
    }

    /**
     * Фильтр по диапазону цены.
     */
    public function scopeByPrice(Builder $query, ?float $min = null, ?float $max = null): Builder
    {
        if ($min !== null) {
            $query->where('base_price', '>=', $min);
        }

        if ($max !== null) {
            $query->where('base_price', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по наличию на складах региона.
     *
     * @param string   $mode     instock | preorder | notavailable
     * @param int|null $regionId ID региона пользователя
     */
    public function scopeInStock(Builder $query, string $mode, ?int $regionId = null): Builder
    {
        if ($regionId === null) {
            return $query; // без региона фильтрация по наличию невозможна
        }

        // Один запрос — все склады региона, разбитые по типу
        $warehouses = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->get(['warehouse_id', 'type'])
            ->groupBy('type');

        $primaryIds  = ($warehouses['primary'] ?? collect())->pluck('warehouse_id')->toArray();
        $preorderIds = ($warehouses['preorder'] ?? collect())->pluck('warehouse_id')->toArray();
        $allIds      = array_merge($primaryIds, $preorderIds);

        $existsWithStock = function ($sub, array $warehouseIds) {
            $sub->select(DB::raw(1))
                ->from('product_warehouse')
                ->whereColumn('product_warehouse.product_id', 'products.id')
                ->where('product_warehouse.quantity', '>', 0);
            if (! empty($warehouseIds)) {
                $sub->whereIn('product_warehouse.warehouse_id', $warehouseIds);
            } else {
                $sub->whereRaw('1 = 0');
            }
        };

        return match ($mode) {
            'instock'      => $query->whereExists(fn ($sub) => $existsWithStock($sub, $primaryIds)),
            'preorder'     => $query->whereExists(fn ($sub) => $existsWithStock($sub, $preorderIds)),
            'notavailable' => $query->whereNotExists(fn ($sub) => $existsWithStock($sub, $allIds)),
            default        => $query,
        };
    }

    /**
     * Исключить товары, которых «нет в наличии» в регионе.
     *
     * Показывает только товары, у которых есть остатки хотя бы на одном
     * складе региона (primary или preorder). Используется по умолчанию,
     * когда пользователь не выбрал явный фильтр наличия.
     *
     * @param int|null $regionId ID региона пользователя
     */
    public function scopeAvailable(Builder $query, ?int $regionId = null): Builder
    {
        if ($regionId === null) {
            return $query; // без региона фильтрация невозможна
        }

        $allWarehouseIds = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->pluck('warehouse_id')
            ->toArray();

        if (empty($allWarehouseIds)) {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($allWarehouseIds) {
            $sub->select(DB::raw(1))
                ->from('product_warehouse')
                ->whereColumn('product_warehouse.product_id', 'products.id')
                ->where('product_warehouse.quantity', '>', 0)
                ->whereIn('product_warehouse.warehouse_id', $allWarehouseIds);
        });
    }

    /**
     * Фильтр по наличию скидки.
     */
    public function scopeInSale(Builder $query, bool $value = true): Builder
    {
        return $value
            ? $query->whereHas('discounts')
            : $query->whereDoesntHave('discounts');
    }

    /**
     * Фильтр по избранному пользователя.
     */
    public function scopeInFavourites(Builder $query, int $userId): Builder
    {
        return $query->whereHas('favoritedByUsers', function (Builder $q) use ($userId) {
            $q->where('users.id', $userId);
        });
    }

    /**
     * Фильтр по значениям атрибутов.
     *
     * @param int[] $valueIds  ID значений атрибутов
     * @param bool  $any       true — OR (любой из), false — AND (все)
     */
    public function scopeByAttributes(Builder $query, array $valueIds, bool $any = false): Builder
    {
        if (empty($valueIds)) {
            return $query;
        }

        if ($any) {
            // OR-логика: товар содержит хотя бы одно из значений
            return $query->whereHas('attributeValues', function (Builder $q) use ($valueIds) {
                $q->whereIn('attribute_value_id', $valueIds);
            });
        }

        // AND-логика: товар содержит ВСЕ указанные значения
        // Группируем по attribute_id, чтобы AND применялся между атрибутами,
        // а OR — между значениями одного атрибута
        $grouped = DB::table('attribute_values')
            ->whereIn('id', $valueIds)
            ->get(['id', 'attribute_id'])
            ->groupBy('attribute_id');

        foreach ($grouped as $attributeId => $values) {
            $ids = $values->pluck('id')->toArray();
            $query->whereHas('attributeValues', function (Builder $q) use ($ids) {
                $q->whereIn('attribute_value_id', $ids);
            });
        }

        return $query;
    }

    /**
     * Фильтр по inline-значениям атрибутов (number, text, boolean).
     *
     * Формат: [attribute_id => [value1, value2, ...], ...]
     * AND между атрибутами, OR внутри одного атрибута.
     */
    public function scopeByInlineAttributes(Builder $query, array $filters): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        // Определяем тип атрибута для каждого attribute_id
        $attrTypes = DB::table('attributes')
            ->whereIn('id', array_keys($filters))
            ->pluck('type', 'id');

        foreach ($filters as $attributeId => $values) {
            if (empty($values)) {
                continue;
            }

            $attributeId = (int) $attributeId;
            $type = $attrTypes[$attributeId] ?? 'text';

            $query->whereHas('attributeValues', function (Builder $q) use ($attributeId, $values, $type) {
                $q->where('attribute_id', $attributeId);

                $column = match ($type) {
                    'number' => 'number_value',
                    'boolean' => 'boolean_value',
                    default => 'text_value',
                };

                if ($type === 'boolean') {
                    $boolValues = array_map(fn ($v) => $v === 'Да' || $v === '1' || $v === 'true' ? 1 : 0, $values);
                    $q->whereIn($column, $boolValues);
                } elseif ($type === 'number') {
                    $numValues = array_map('floatval', $values);
                    $q->whereIn($column, $numValues);
                } else {
                    $q->whereIn($column, $values);
                }
            });
        }

        return $query;
    }
}
