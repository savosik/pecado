<?php

namespace App\Services\Product;


use App\Models\Product;
use App\Models\Region;
use App\Services\CurrencyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductQueryService
{
    /**
     * Преобразовать продукт в массив для фронтенда.
     *
     * Ожидается, что у товара загружены атрибуты:
     *   - primary_stock (остатки на primary складах региона)
     *   - preorder_stock (остатки на preorder складах региона)
     */
    public static function productToArray($product): array
    {
        $galleryMedia = $product->getMedia('additional');

        return [
            'id'                => $product->id,
            'name'              => $product->name,
            'slug'              => $product->slug,
            'sku'               => $product->sku,
            'external_id'       => $product->external_id,
            'base_price'        => (float) $product->base_price,
            'brand_name'        => $product->brand?->name,
            'brand_slug'        => $product->brand?->slug,
            'main_image'        => $product->getFirstMediaUrl('main') ?: null,
            'thumbnail'         => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main') ?: null,
            'gallery_urls'      => $galleryMedia->map(fn ($m) => [
                'url'   => $m->getUrl(),
                'thumb' => $m->getUrl('thumb') ?: $m->getUrl(),
            ])->values()->toArray(),
            'is_new'            => $product->is_new,
            'is_bestseller'     => $product->is_bestseller,
            // Для кешированных данных primary_stock/preorder_stock = null → 0.
            // Это ожидаемо: enrichProductsWithStock() перезапишет значения после извлечения из кеша.
            'stock_quantity'    => (int) ($product->primary_stock ?? 0),
            'preorder_quantity' => (int) ($product->preorder_stock ?? 0),
            'tags'              => $product->tags->map(fn ($tag) => [
                'id'   => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'type' => $tag->type,
            ])->values()->toArray(),
        ];
    }

    /**
     * Стандартные eager-загрузки для товаров (без warehouses — используем subselect sums).
     */
    public static function productEagerLoads(): array
    {
        return ['brand', 'media', 'tags'];
    }

    /**
     * Получить ID складов региона текущего пользователя.
     *
     * @return array{primary: int[], preorder: int[]}
     */
    public static function getRegionWarehouseIds(): array
    {
        $user = Auth::user();
        if (!$user || !$user->region_id) {
            return ['primary' => [], 'preorder' => []];
        }

        $rows = DB::table('region_warehouse')
            ->where('region_id', $user->region_id)
            ->select('warehouse_id', 'type')
            ->get();

        return [
            'primary'  => $rows->where('type', 'primary')->pluck('warehouse_id')->toArray(),
            'preorder' => $rows->where('type', 'preorder')->pluck('warehouse_id')->toArray(),
        ];
    }

    /**
     * Добавить к запросу суммы остатков по primary и preorder складам региона пользователя.
     */
    public static function withRegionStockSums($query): void
    {
        $wh = self::getRegionWarehouseIds();

        // Primary stock
        if (!empty($wh['primary'])) {
            $query->addSelect([
                'primary_stock' => DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('product_warehouse.warehouse_id', $wh['primary']),
            ]);
        } else {
            $query->selectRaw('0 as primary_stock');
        }

        // Preorder stock
        if (!empty($wh['preorder'])) {
            $query->addSelect([
                'preorder_stock' => DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('product_warehouse.warehouse_id', $wh['preorder']),
            ]);
        } else {
            $query->selectRaw('0 as preorder_stock');
        }
    }

    /**
     * Обогатить массив товаров остатками по региону текущего пользователя.
     * Используется для товаров, загруженных из кеша (без stock sums).
     */
    public static function enrichProductsWithStock(array $products): array
    {
        $wh = self::getRegionWarehouseIds();
        $productIds = collect($products)->pluck('id')->toArray();

        if (empty($productIds)) {
            return $products;
        }

        // Загрузить остатки одним запросом
        $stockRows = DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', array_merge($wh['primary'], $wh['preorder']))
            ->select('product_id', 'warehouse_id', 'quantity')
            ->get();

        $primaryIds = array_flip($wh['primary']);
        $preorderIds = array_flip($wh['preorder']);

        // Группируем по product_id
        $stockMap = [];
        foreach ($stockRows as $row) {
            $pid = $row->product_id;
            if (!isset($stockMap[$pid])) {
                $stockMap[$pid] = ['primary' => 0, 'preorder' => 0];
            }
            if (isset($primaryIds[$row->warehouse_id])) {
                $stockMap[$pid]['primary'] += $row->quantity;
            }
            if (isset($preorderIds[$row->warehouse_id])) {
                $stockMap[$pid]['preorder'] += $row->quantity;
            }
        }

        return array_map(function ($product) use ($stockMap) {
            $stock = $stockMap[$product['id']] ?? ['primary' => 0, 'preorder' => 0];
            $product['stock_quantity'] = $stock['primary'];
            $product['preorder_quantity'] = $stock['preorder'];
            return $product;
        }, $products);
    }

    /**
     * Обогатить подборки остатками по региону текущего пользователя.
     */
    public static function enrichSelectionsWithStock(array $selections): array
    {
        return array_map(function ($selection) {
            $selection['products'] = self::enrichProductsWithStock($selection['products'] ?? []);
            return $selection;
        }, $selections);
    }

    /**
     * Загрузить карту индивидуальных цен текущего пользователя для переданных товаров.
     * v7: Используем таблицу individual_prices вместо старой модели Discount.
     *
     * @param  array  $products  массив товаров (каждый с 'id' и 'external_id')
     * @return Collection  Коллекция [product_id => ['price' => float, 'discount_percent' => float]]
     */
    public static function loadIndividualPriceMap(array $products): Collection
    {
        $user = Auth::user();
        if (!$user || !$user->erp_id) {
            return collect();
        }

        // v7.1: Собираем product IDs (числовые) напрямую
        $productIds = collect($products)
            ->filter(fn ($p) => !empty($p['id']))
            ->pluck('id')
            ->toArray();

        if (empty($productIds)) {
            return collect();
        }

        // Запрос по числовым ID — в 3-5x быстрее чем по UUID
        $prices = DB::table('individual_prices')
            ->where('partner_id', $user->id)
            ->whereIn('product_id', $productIds)
            ->select('product_id', 'price')
            ->get();

        return $prices->mapWithKeys(function ($row) {
            return [(int) $row->product_id => (float) $row->price];
        });
    }

    /**
     * Применить карту индивидуальных цен к массиву товаров.
     */
    public static function applyIndividualPriceMap(array $products, Collection $priceMap): array
    {
        if ($priceMap->isEmpty()) {
            return $products;
        }

        return array_map(function ($product) use ($priceMap) {
            $individualPrice = $priceMap[$product['id']] ?? null;
            if ($individualPrice !== null) {
                $basePrice = (float) ($product['base_price'] ?? 0);
                $discountPercent = $basePrice > 0
                    ? round((1 - $individualPrice / $basePrice) * 100, 2)
                    : 0;

                $product['individual_price'] = $individualPrice;
                $product['discount_percentage'] = max(0, $discountPercent);
                $product['sale_price'] = $individualPrice;
                $product['has_discount'] = $discountPercent > 0;
            }
            return $product;
        }, $products);
    }

    /**
     * Обогатить массив товаров индивидуальными ценами текущего пользователя.
     * Совместимость: сохраняем ключи discount_percentage и sale_price для фронтенда.
     */
    public static function enrichProductsWithDiscounts(array $products, ?Collection $priceMap = null): array
    {
        $user = Auth::user();
        if (!$user) {
            return $products;
        }

        if (empty($products)) {
            return $products;
        }

        $priceMap = $priceMap ?? self::loadIndividualPriceMap($products);

        return self::applyIndividualPriceMap($products, $priceMap);
    }

    /**
     * Обогатить подборки индивидуальными ценами текущего пользователя.
     * Все ID товаров собираются заранее, цены загружаются одним запросом.
     */
    public static function enrichSelectionsWithDiscounts(array $selections): array
    {
        $user = Auth::user();
        if (!$user) {
            return $selections;
        }

        // Собираем все товары из всех подборок
        $allProducts = collect($selections)
            ->flatMap(fn ($sel) => $sel['products'] ?? [])
            ->toArray();

        $priceMap = self::loadIndividualPriceMap($allProducts);

        return array_map(function ($selection) use ($priceMap) {
            $selection['products'] = self::applyIndividualPriceMap($selection['products'] ?? [], $priceMap);
            return $selection;
        }, $selections);
    }

    /**
     * Конвертировать цены товаров в валюту текущего пользователя.
     */
    public static function convertProductsPrices(array $products): array
    {
        $user = Auth::user();
        if (!$user || !$user->region?->currency) {
            return $products;
        }

        $currency = $user->region->currency;
        if ($currency->is_base) {
            return $products;
        }

        return app(CurrencyService::class)->convertProductPrices($products, $currency);
    }

    /**
     * Конвертировать цены товаров в подборках в валюту текущего пользователя.
     */
    public static function convertSelectionsPrices(array $selections): array
    {
        return array_map(function ($selection) {
            $selection['products'] = self::convertProductsPrices($selection['products'] ?? []);
            return $selection;
        }, $selections);
    }
}
