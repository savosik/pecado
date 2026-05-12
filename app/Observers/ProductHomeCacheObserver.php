<?php

namespace App\Observers;

use App\Models\Product;
use App\Support\HomeCache;

/**
 * Сбрасывает кеши главной страницы при изменениях товаров.
 *
 * Подборки (selections), новинки и бестселлеры хранят «снимки» Product::productToArray:
 * name/slug/sku/base_price/brand/main_image/is_new/is_bestseller/tags.
 * Любое из этих изменений делает кеш устаревшим, поэтому на updated мы инвалидируем
 * соответствующий набор только если затронуты релевантные поля — иначе массовый ERP-апдейт
 * (например stock_quantity, не входящий в productToArray) будет бесцельно сбрасывать кеш.
 */
class ProductHomeCacheObserver
{
    /**
     * Поля Product, попадающие в кешированные массивы подборок/новинок/бестселлеров.
     *
     * @var array<int, string>
     */
    private const RELEVANT_FIELDS = [
        'name',
        'slug',
        'sku',
        'variant_name',
        'external_id',
        'base_price',
        'brand_id',
        'is_new',
        'is_bestseller',
    ];

    public function created(Product $product): void
    {
        HomeCache::flushProductRelated();
    }

    public function deleted(Product $product): void
    {
        HomeCache::flushProductRelated();
    }

    public function updated(Product $product): void
    {
        $dirty = array_keys($product->getChanges());

        if (array_intersect($dirty, self::RELEVANT_FIELDS) === []) {
            return;
        }

        if (in_array('is_new', $dirty, true)) {
            HomeCache::flushNewProducts();
        }

        if (in_array('is_bestseller', $dirty, true)) {
            HomeCache::flushBestsellers();
        }

        HomeCache::flushSelections();
    }
}
