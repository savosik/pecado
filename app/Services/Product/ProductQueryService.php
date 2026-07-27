<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Services\CurrencyService;
use App\Services\Promotion\ActivePromotionRuleCache;
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
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'variant_name' => $product->variant_name,
            'external_id' => $product->external_id,
            'base_price' => (float) $product->base_price,
            'brand_name' => $product->brand?->name,
            'brand_slug' => $product->brand?->slug,
            'main_image' => $product->getFirstMediaUrl('main') ?: null,
            'thumbnail' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main') ?: null,
            'gallery_urls' => $galleryMedia->map(fn ($m) => [
                'url' => $m->getUrl(),
                'thumb' => $m->getUrl('thumb') ?: $m->getUrl(),
            ])->values()->toArray(),
            'is_new' => $product->is_new,
            'is_bestseller' => $product->is_bestseller,
            'is_marked' => $product->is_marked,
            // Срок годности (date-time атрибут из 1С) для бейджа «до MM/YY».
            // ISO-дата; фронт форматирует в MM/YY. null — если срок не указан.
            'shelf_life' => $product->shelfLifeValue?->datetime_value?->toDateString(),
            // Для кешированных данных primary_stock/preorder_stock = null → 0.
            // Это ожидаемо: enrichProductsWithStock() перезапишет значения после извлечения из кеша.
            'stock_quantity' => (int) ($product->primary_stock ?? 0),
            'preorder_quantity' => (int) ($product->preorder_stock ?? 0),
            'tags' => $product->tags->map(fn ($tag) => [
                'id' => $tag->id,
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
        return ['brand', 'media', 'tags', 'shelfLifeValue'];
    }

    /**
     * Получить ID складов региона текущего пользователя.
     *
     * Для гостя используется регион по умолчанию — чтобы запросы остатков
     * на SQL-уровне работали консистентно с фасетами. Наружу (в ответ API)
     * эти поля для гостя всё равно не попадают — см. CatalogApiController.
     *
     * @return array{primary: int[], preorder: int[]}
     */
    public static function getRegionWarehouseIds(): array
    {
        $user = Auth::user();
        $regionId = $user !== null ? $user->region_id : null;
        $regionId = $regionId ?? Region::defaultId();
        if (! $regionId) {
            return ['primary' => [], 'preorder' => []];
        }

        $rows = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->select('warehouse_id', 'type')
            ->get();

        return [
            'primary' => $rows->where('type', 'primary')->pluck('warehouse_id')->toArray(),
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
        if (! empty($wh['primary'])) {
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
        if (! empty($wh['preorder'])) {
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
            if (! isset($stockMap[$pid])) {
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
     * Проставить товарам флаг has_defects — есть ли у товара уценка в продаже.
     *
     * Одним запросом на всю пачку (см. DefectStockService::hasSellableDefectsMap):
     * true только если есть опубликованная партия с ценой и свободным остатком.
     * Флаг булев, не ценовой, поэтому отдаётся всем — в том числе гостям (значок
     * «уценка» в списке видят все, покупают только авторизованные).
     */
    public static function enrichProductsWithDefects(array $products): array
    {
        if (empty($products)) {
            return $products;
        }

        $ids = collect($products)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($ids)) {
            return $products;
        }

        $map = app(\App\Contracts\Defect\DefectStockServiceInterface::class)
            ->hasSellableDefectsMap($ids);

        return array_map(function ($product) use ($map) {
            $product['has_defects'] = $map[$product['id']] ?? false;

            return $product;
        }, $products);
    }

    /**
     * Проставить товарам маркеры участия в акции.
     *
     * Два разных признака, их нельзя смешивать:
     *  - `has_promotion` — товар участвует в акции (контентная привязка
     *    `product_promotion` **или** условие правила `promotion_rule_product`);
     *  - `is_promo_reward` — товар сам выдаётся как промо-позиция
     *    (`role = reward`). Это не «купи меня», а «это можно получить».
     *
     * Флаги булевы и не раскрывают цены — отдаются в том числе гостям, как и
     * маркер уценки. Регион учитывается той же логикой, что и scope forRegion:
     * контент без привязки к регионам виден всем, с привязкой — только своим.
     *
     * Один запрос на всю пачку (UNION по двум pivot-таблицам): список активных
     * правил берётся из кэша, поэтому переключение правила не тянет
     * переиндексацию Meilisearch и не добавляет запросов на страницу.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  int|null  $regionId  Регион; по умолчанию — регион текущего пользователя
     * @return array<int, array<string, mixed>>
     */
    public static function enrichProductsWithPromotions(array $products, ?int $regionId = null): array
    {
        if (empty($products)) {
            return $products;
        }

        $ids = collect($products)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($ids)) {
            return $products;
        }

        $regionId ??= Auth::user()?->region_id;

        [$conditionMap, $rewardMap, $nameMap] = self::promotionMarkerMaps($ids, $regionId);

        return array_map(function ($product) use ($conditionMap, $rewardMap, $nameMap) {
            $id = (int) $product['id'];
            $names = $nameMap[$id] ?? [];

            $product['has_promotion'] = isset($conditionMap[$id]);
            $product['is_promo_reward'] = isset($rewardMap[$id]);
            // Тултип показываем только когда акция одна: перечислять три названия
            // в подсказке на карточке некуда
            $product['promotion_name'] = count($names) === 1 ? reset($names) : null;

            return $product;
        }, $products);
    }

    /**
     * Карты «товар → участвует / выдаётся» и названия акций одним запросом.
     *
     * @param  int[]  $ids
     * @return array{0: array<int, true>, 1: array<int, true>, 2: array<int, string[]>}
     */
    private static function promotionMarkerMaps(array $ids, ?int $regionId): array
    {
        $morphClass = (new Promotion)->getMorphClass();

        // Контентная привязка: активная акция, видимая в регионе пользователя
        $content = DB::table('product_promotion as pp')
            ->join('promotions as p', 'p.id', '=', 'pp.promotion_id')
            ->whereIn('pp.product_id', $ids)
            ->where('p.is_active', true)
            ->where(function ($query) use ($morphClass, $regionId) {
                $query->whereNotExists(function ($sub) use ($morphClass) {
                    $sub->select(DB::raw(1))
                        ->from('regionables')
                        ->whereColumn('regionables.regionable_id', 'p.id')
                        ->where('regionables.regionable_type', $morphClass);
                });

                if ($regionId) {
                    $query->orWhereExists(function ($sub) use ($morphClass, $regionId) {
                        $sub->select(DB::raw(1))
                            ->from('regionables')
                            ->whereColumn('regionables.regionable_id', 'p.id')
                            ->where('regionables.regionable_type', $morphClass)
                            ->where('regionables.region_id', $regionId);
                    });
                }
            })
            ->select('pp.product_id', DB::raw("'condition' as role"), 'p.name as name');

        $ruleIds = self::activePromotionRuleIds($regionId);

        if ($ruleIds !== []) {
            $content->union(
                DB::table('promotion_rule_product as prp')
                    ->join('promotion_rules as pr', 'pr.id', '=', 'prp.promotion_rule_id')
                    ->whereIn('prp.product_id', $ids)
                    ->whereIn('prp.promotion_rule_id', $ruleIds)
                    ->select('prp.product_id', 'prp.role', 'pr.name as name')
            );
        }

        $conditionMap = [];
        $rewardMap = [];
        $nameMap = [];

        foreach ($content->get() as $row) {
            $productId = (int) $row->product_id;

            if ($row->role === PromotionRule::ROLE_REWARD) {
                $rewardMap[$productId] = true;
            } else {
                $conditionMap[$productId] = true;
                $nameMap[$productId][$row->name] = (string) $row->name;
            }
        }

        return [$conditionMap, $rewardMap, $nameMap];
    }

    /**
     * Активные правила, действующие в регионе.
     *
     * Список берётся из кэша движка (60 с), регион проверяется в PHP:
     * `audience.region_ids` — JSON, и фильтровать его в SQL пришлось бы
     * по-разному в MySQL и SQLite.
     *
     * @return int[]
     */
    private static function activePromotionRuleIds(?int $regionId): array
    {
        return app(ActivePromotionRuleCache::class)
            ->activeAt()
            ->filter(function (PromotionRule $rule) use ($regionId) {
                $regions = array_values(array_filter(array_map(
                    'intval',
                    (array) ($rule->audience['region_ids'] ?? []),
                )));

                // Правило без привязки к регионам работает везде
                return $regions === [] || ($regionId !== null && in_array($regionId, $regions, true));
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Обогатить подборки маркерами акции.
     */
    public static function enrichSelectionsWithPromotions(array $selections): array
    {
        return array_map(function ($selection) {
            $selection['products'] = self::enrichProductsWithPromotions($selection['products'] ?? []);

            return $selection;
        }, $selections);
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
     * @return Collection Коллекция [product_id => ['price' => float, 'discount_percent' => float]]
     */
    public static function loadIndividualPriceMap(array $products): Collection
    {
        $user = Auth::user();
        if (! $user || ! $user->erp_id) {
            return collect();
        }

        // v7.1: Собираем product IDs (числовые) напрямую
        $productIds = collect($products)
            ->filter(fn ($p) => ! empty($p['id']))
            ->pluck('id')
            ->toArray();

        if (empty($productIds)) {
            return collect();
        }

        // Запрос по числовым ID — в 3-5x быстрее чем по UUID
        return \App\Services\Pricing\IndividualPriceProxy::loadPriceMap($user->id, $productIds);
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
        if (! $user) {
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
        if (! $user) {
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
        if (! $user || ! $user->region?->currency) {
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
