<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Region;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Видимость брендов и категорий на витрине по наличию товаров.
 *
 * Бренд или категория показываются в списках (каталог-панель, страница брендов,
 * соседние категории, подвал, подсказки поиска, sitemap) только когда хотя бы один
 * видимый товар имеет остаток > 0 на складах региона типа `primary` или `preorder`.
 * Ничего не хранится и не выключается руками: остатки приходят из 1С, и список
 * сам сжимается, когда товар кончается, и сам расширяется с приходом.
 *
 * Родительский бренд виден, если есть остаток у него или у любого дочернего;
 * категория видна, если остаток есть на всю глубину подкатегорий.
 *
 * Результат кешируется на {@see self::TTL} секунд на регион. Регион гостя —
 * {@see Region::defaultId()}. Без региона или без складов у региона фильтрация
 * невозможна — тогда метод возвращает null, и список показывается целиком
 * (та же логика, что у Product::scopeAvailable()).
 */
class StockVisibility
{
    public const TTL = 600;

    /**
     * Идентификаторы брендов с остатками (включая родителей дочерних брендов).
     *
     * @return list<int>|null null — фильтрация невозможна, показывать всё
     */
    public function brandIds(?int $regionId = null): ?array
    {
        $regionId ??= Region::defaultId();
        if ($regionId === null) {
            return null;
        }

        return Cache::remember(self::key('brands', $regionId), self::TTL, function () use ($regionId): ?array {
            $warehouseIds = $this->warehouseIds($regionId);
            if ($warehouseIds === []) {
                return null;
            }

            $direct = $this->stockedProducts($warehouseIds)
                ->whereNotNull('products.brand_id')
                ->distinct()
                ->pluck('products.brand_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($direct === []) {
                return [];
            }

            $parents = Brand::query()
                ->whereIn('id', $direct)
                ->whereNotNull('parent_id')
                ->distinct()
                ->pluck('parent_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return array_values(array_unique(array_merge($direct, $parents)));
        });
    }

    /**
     * Идентификаторы категорий с остатками на всю глубину (включая всех предков).
     *
     * @return list<int>|null null — фильтрация невозможна, показывать всё
     */
    public function categoryIds(?int $regionId = null): ?array
    {
        $regionId ??= Region::defaultId();
        if ($regionId === null) {
            return null;
        }

        return Cache::remember(self::key('categories', $regionId), self::TTL, function () use ($regionId): ?array {
            $warehouseIds = $this->warehouseIds($regionId);
            if ($warehouseIds === []) {
                return null;
            }

            $direct = $this->stockedProducts($warehouseIds)
                ->whereNotNull('products.category_id')
                ->distinct()
                ->pluck('products.category_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($direct === []) {
                return [];
            }

            // Предки через nested set: категория-предок охватывает потомка по _lft/_rgt.
            return DB::table('categories as ancestor')
                ->join('categories as descendant', function ($join) {
                    $join->whereColumn('descendant._lft', '>=', 'ancestor._lft')
                        ->whereColumn('descendant._rgt', '<=', 'ancestor._rgt');
                })
                ->whereIn('descendant.id', $direct)
                ->distinct()
                ->pluck('ancestor.id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        });
    }

    /**
     * Сбросить кеш региона (или всех регионов, если регион не передан).
     */
    public function forget(?int $regionId = null): void
    {
        $regionIds = $regionId !== null
            ? [$regionId]
            : Region::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($regionIds as $id) {
            Cache::forget(self::key('brands', $id));
            Cache::forget(self::key('categories', $id));
        }
    }

    /**
     * Склады региона, по которым считается наличие (основные и предзаказ).
     *
     * @return list<int>
     */
    private function warehouseIds(int $regionId): array
    {
        return DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->whereIn('type', ['primary', 'preorder'])
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Видимые товары (глобальный HiddenScope) с остатком > 0 хотя бы на одном из складов.
     *
     * @param  list<int>  $warehouseIds
     */
    private function stockedProducts(array $warehouseIds): \Illuminate\Database\Eloquent\Builder
    {
        return Product::query()
            ->join('product_warehouse', 'product_warehouse.product_id', '=', 'products.id')
            ->whereIn('product_warehouse.warehouse_id', $warehouseIds)
            ->where('product_warehouse.quantity', '>', 0);
    }

    private static function key(string $kind, int $regionId): string
    {
        return "catalog.stock-visibility.{$kind}.{$regionId}";
    }
}
