<?php

namespace App\Services\Product;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Сервис агрегации фасетных данных для фильтров каталога.
 *
 * Принимает уже отфильтрованный Builder (baseQuery) — товары,
 * к которым применены скоупы — и считает агрегаты для UI:
 * бренды, категории, атрибуты, ценовые интервалы.
 *
 * Контракт: baseQuery должен быть запросом к таблице `products`.
 * Метод cloneBaseIds() сбросит select и оставит только `products.id`,
 * поэтому addSelect/selectRaw из скоупов (например withRegionStockSums)
 * не повлияют на результат.
 *
 * Оптимизация: каждый метод — один SQL-запрос с GROUP BY.
 */
class CatalogFacetService
{
    /**
     * Количество ценовых бакетов по умолчанию.
     */
    private const DEFAULT_BUCKET_COUNT = 5;

    /**
     * Агрегация фасетов по атрибутам (attribute_values через product_attribute_values).
     *
     * Один SQL на атрибут (при наличии selectedValueIds) или один общий SQL.
     * Учитываются только атрибуты с is_filterable = 1.
     *
     * При наличии $selectedValueIds реализуется per-attribute exclusion:
     * для каждого атрибута считаются фасеты на запросе, где применены
     * фильтры по ДРУГИМ атрибутам, но НЕ по текущему.
     * Это позволяет видеть все варианты внутри группы (OR),
     * при этом учитывая пересечение с другими атрибутами (AND).
     *
     * @param  int[]  $selectedValueIds  Текущие выбранные attribute_value_ids
     * @return array<int, array{id: int, name: string, values: array}>
     */
    public function getAttributeFacets(Builder $baseQuery, array $selectedValueIds = []): array
    {
        // Если ничего не выбрано — один простой запрос для всех атрибутов
        if (empty($selectedValueIds)) {
            return $this->computeAttributeFacets($baseQuery);
        }

        // Группируем выбранные значения по attribute_id
        $grouped = DB::table('attribute_values')
            ->whereIn('id', $selectedValueIds)
            ->get(['id', 'attribute_id'])
            ->groupBy('attribute_id');

        // Получаем список всех фильтруемых атрибутов
        $filterableAttributes = DB::table('attributes')
            ->where('is_filterable', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        // Оптимизация: группируем атрибуты по набору «чужих» value_ids,
        // чтобы вместо N запросов (по 1 на атрибут) делать M запросов,
        // где M = количество уникальных наборов cross-фильтров (обычно 2-3).
        $buckets = []; // key = serialized otherValueIds, value = { attrIds: [], otherValueIds: [] }
        $attrMeta = []; // attribute_id => { id, name }

        foreach ($filterableAttributes as $attribute) {
            $attrMeta[$attribute->id] = $attribute;

            $otherValueIds = [];
            foreach ($grouped as $attrId => $values) {
                if ((int) $attrId !== $attribute->id) {
                    $otherValueIds = array_merge($otherValueIds, $values->pluck('id')->toArray());
                }
            }
            sort($otherValueIds);
            $bucketKey = implode(',', $otherValueIds);

            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'attrIds' => [],
                    'otherValueIds' => $otherValueIds,
                ];
            }
            $buckets[$bucketKey]['attrIds'][] = $attribute->id;
        }

        // Один SQL-запрос на каждый уникальный набор cross-фильтров
        $rawResults = []; // attribute_id => rows

        foreach ($buckets as $bucket) {
            $attrQuery = clone $baseQuery;
            if (!empty($bucket['otherValueIds'])) {
                $attrQuery->byAttributes($bucket['otherValueIds']);
            }

            $productIds = $this->cloneBaseIds($attrQuery);

            $rows = DB::table('product_attribute_values as pav')
                ->joinSub($productIds, 'filtered', 'filtered.id', '=', 'pav.product_id')
                ->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
                ->whereIn('pav.attribute_id', $bucket['attrIds'])
                ->select([
                    'pav.attribute_id',
                    'av.id as value_id',
                    'av.value as value_name',
                    DB::raw('COUNT(DISTINCT pav.product_id) as count'),
                ])
                ->groupBy('pav.attribute_id', 'av.id', 'av.value')
                ->orderBy('av.sort_order')
                ->get();

            foreach ($rows->groupBy('attribute_id') as $attrId => $attrRows) {
                $rawResults[(int) $attrId] = $attrRows;
            }
        }

        // Собираем результат в порядке sort_order атрибутов
        $result = [];

        foreach ($filterableAttributes as $attribute) {
            $rows = $rawResults[$attribute->id] ?? null;
            if (!$rows || $rows->isEmpty()) {
                continue;
            }

            $values = [];
            foreach ($rows as $row) {
                $values[] = [
                    'id' => $row->value_id,
                    'value' => $row->value_name,
                    'count' => (int) $row->count,
                ];
            }

            $result[] = [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'values' => $values,
            ];
        }

        return $result;
    }

    /**
     * Простой расчёт фасетов по всем атрибутам одним SQL-запросом.
     *
     * Используется когда нет выбранных attribute_value_ids.
     */
    private function computeAttributeFacets(Builder $baseQuery): array
    {
        $productIds = $this->cloneBaseIds($baseQuery);

        $rows = DB::table('product_attribute_values as pav')
            ->joinSub($productIds, 'filtered', 'filtered.id', '=', 'pav.product_id')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
            ->where('a.is_filterable', true)
            ->select([
                'a.id as attribute_id',
                'a.name as attribute_name',
                'av.id as value_id',
                'av.value as value_name',
                DB::raw('COUNT(DISTINCT pav.product_id) as count'),
            ])
            ->groupBy('a.id', 'a.name', 'av.id', 'av.value')
            ->orderBy('a.sort_order')
            ->orderBy('av.sort_order')
            ->get();

        // Группируем строки по атрибуту
        $grouped = [];
        foreach ($rows as $row) {
            $attrId = $row->attribute_id;

            if (! isset($grouped[$attrId])) {
                $grouped[$attrId] = [
                    'id' => $attrId,
                    'name' => $row->attribute_name,
                    'values' => [],
                ];
            }

            $grouped[$attrId]['values'][] = [
                'id' => $row->value_id,
                'value' => $row->value_name,
                'count' => (int) $row->count,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Агрегация фасетов по брендам.
     *
     * Один SQL: GROUP BY brand_id с JOIN brands.
     *
     * @return array<int, array{id: int, name: string, slug: string, count: int}>
     */
    public function getBrandFacets(Builder $baseQuery): array
    {
        return $this->getEntityFacets($baseQuery, 'brands', 'brand_id');
    }

    /**
     * Агрегация фасетов по категориям.
     *
     * Один SQL: GROUP BY category_id с JOIN categories.
     *
     * @return array<int, array{id: int, name: string, slug: string, count: int}>
     */
    public function getCategoryFacets(Builder $baseQuery): array
    {
        return $this->getEntityFacets($baseQuery, 'categories', 'category_id');
    }

    /**
     * Ценовые интервалы: min, max и динамические buckets.
     *
     * Два SQL-запроса:
     *  1. MIN/MAX base_price.
     *  2. COUNT по каждому бакету через CASE WHEN (с параметризованными bindings).
     *
     * Бакеты рассчитываются динамически (линейное разбиение диапазона).
     *
     * @return array{min: float, max: float, buckets: array}
     */
    public function getPriceIntervals(Builder $baseQuery): array
    {
        $productIds = $this->cloneBaseIds($baseQuery);

        // 1. MIN/MAX
        $range = DB::table('products as p')
            ->joinSub($productIds, 'filtered', 'filtered.id', '=', 'p.id')
            ->selectRaw('MIN(p.base_price) as min_price, MAX(p.base_price) as max_price')
            ->first();

        $min = (float) ($range->min_price ?? 0);
        $max = (float) ($range->max_price ?? 0);

        if ($min >= $max) {
            return [
                'min' => $min,
                'max' => $max,
                'buckets' => [],
            ];
        }

        // 2. Рассчитать границы бакетов
        $boundaries = $this->calculateBucketBoundaries($min, $max);

        // 3. Посчитать количество товаров в каждом бакете одним запросом (с bindings)
        $query = DB::table('products as p')
            ->joinSub(
                (clone $baseQuery)->select('products.id')->getQuery(),
                'filtered',
                'filtered.id',
                '=',
                'p.id'
            );

        $cases = [];
        $bindings = [];
        foreach ($boundaries as $i => $bucket) {
            $from = $bucket['from'];
            $to = $bucket['to'];

            if ($i === count($boundaries) - 1) {
                // Последний бакет включает верхнюю границу (<=)
                $cases[] = "SUM(CASE WHEN p.base_price >= ? AND p.base_price <= ? THEN 1 ELSE 0 END) as bucket_{$i}";
            } else {
                $cases[] = "SUM(CASE WHEN p.base_price >= ? AND p.base_price < ? THEN 1 ELSE 0 END) as bucket_{$i}";
            }
            $bindings[] = $from;
            $bindings[] = $to;
        }

        $counts = $query->selectRaw(implode(', ', $cases), $bindings)->first();

        $buckets = [];
        foreach ($boundaries as $i => $bucket) {
            $buckets[] = [
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'count' => (int) ($counts->{"bucket_{$i}"} ?? 0),
            ];
        }

        return [
            'min' => $min,
            'max' => $max,
            'buckets' => $buckets,
        ];
    }

    /**
     * Общая агрегация фасетов по сущности (бренд, категория).
     *
     * @param  string $table     Таблица сущности (brands, categories)
     * @param  string $fkColumn  FK-колонка в products (brand_id, category_id)
     * @return array<int, array{id: int, name: string, slug: string, count: int}>
     */
    private function getEntityFacets(Builder $baseQuery, string $table, string $fkColumn): array
    {
        $productIds = $this->cloneBaseIds($baseQuery);
        $alias = $table[0]; // 'b' для brands, 'c' для categories

        return DB::table("products as p")
            ->joinSub($productIds, 'filtered', 'filtered.id', '=', 'p.id')
            ->join("{$table} as {$alias}", "{$alias}.id", '=', "p.{$fkColumn}")
            ->select([
                "{$alias}.id",
                "{$alias}.name",
                "{$alias}.slug",
                DB::raw('COUNT(*) as count'),
            ])
            ->groupBy("{$alias}.id", "{$alias}.name", "{$alias}.slug")
            ->orderBy("{$alias}.name")
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'count' => (int) $row->count,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Клонировать базовый запрос и извлечь только ID товаров (подзапрос).
     *
     * Сбрасывает все select/addSelect и оставляет только products.id.
     */
    private function cloneBaseIds(Builder $baseQuery): \Illuminate\Database\Query\Builder
    {
        return (clone $baseQuery)
            ->select('products.id')
            ->getQuery();
    }

    /**
     * Рассчитать границы бакетов линейным разбиением диапазона.
     *
     * Округляет границы до «красивых» чисел для удобства пользователя.
     *
     * @return array<int, array{from: float, to: float}>
     */
    private function calculateBucketBoundaries(float $min, float $max): array
    {
        $count = self::DEFAULT_BUCKET_COUNT;
        $range = $max - $min;
        $step = $range / $count;

        // Определяем порядок для округления
        $magnitude = pow(10, floor(log10(max($step, 1))));
        $roundedStep = ceil($step / $magnitude) * $magnitude;

        $roundedMin = floor($min / $magnitude) * $magnitude;

        $boundaries = [];
        for ($i = 0; $i < $count; $i++) {
            $from = $roundedMin + ($i * $roundedStep);
            $to = $roundedMin + (($i + 1) * $roundedStep);

            // Не допускаем бакеты ниже реального min или выше реального max
            $from = max($from, $min);
            $to = min($to, $max);

            if ($from >= $to && $i > 0) {
                break; // Диапазон исчерпан
            }

            $boundaries[] = [
                'from' => round($from, 2),
                'to' => round($to, 2),
            ];
        }

        return $boundaries;
    }
}
