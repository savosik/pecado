<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Services\Product\ProductQueryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProductSelectionController extends Controller
{
    /** Ключи кеша подборок главной страницы. */
    public const CACHE_KEY_SELECTIONS  = 'user.product_selections.active';
    public const CACHE_KEY_NEW         = 'user.products.new';
    public const CACHE_KEY_BESTSELLERS = 'user.products.bestsellers';

    /**
     * Получить подборки товаров с кешированием (10 мин).
     *
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации/остатков.
     * Остатки добавляются после кеша через enrichSelectionsWithStock.
     */
    public static function getCachedSelections(?int $regionId = null): array
    {
        $cacheKey = self::CACHE_KEY_SELECTIONS . '.' . ($regionId ?? 'all');

        return Cache::remember($cacheKey, 600, function () use ($regionId) {
            return ProductSelection::active()
                ->showOnHome()
                ->ordered()
                ->forRegion($regionId)
                ->with(['featuredProducts' => function ($query) {
                    $query->with(ProductQueryService::productEagerLoads());
                }])
                ->get()
                ->map(function (ProductSelection $selection) {
                    return [
                        'id'                => $selection->id,
                        'name'              => $selection->name,
                        'slug'              => $selection->slug,
                        'short_description' => $selection->short_description,
                        'desktop_image'     => $selection->getFirstMediaUrl('desktop') ?: null,
                        'mobile_image'      => $selection->getFirstMediaUrl('mobile') ?: null,
                        'products'          => $selection->featuredProducts
                            ->map(fn ($p) => ProductQueryService::productToArray($p))
                            ->values()
                            ->toArray(),
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Новинки (is_new = true), кеш 10 мин.
     *
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации/остатков.
     */
    public static function getCachedNewProducts(int $limit = 10): array
    {
        return Cache::remember(self::CACHE_KEY_NEW . ".{$limit}", 600, function () use ($limit) {
            return Product::where('is_new', true)
                ->with(ProductQueryService::productEagerLoads())
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn ($p) => ProductQueryService::productToArray($p))
                ->values()
                ->toArray();
        });
    }

    /**
     * Бестселлеры (is_bestseller = true), кеш 10 мин.
     *
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации/остатков.
     */
    public static function getCachedBestsellerProducts(int $limit = 10): array
    {
        return Cache::remember(self::CACHE_KEY_BESTSELLERS . ".{$limit}", 600, function () use ($limit) {
            return Product::where('is_bestseller', true)
                ->with(ProductQueryService::productEagerLoads())
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn ($p) => ProductQueryService::productToArray($p))
                ->values()
                ->toArray();
        });
    }

    /**
     * Сбросить все кеши подборок главной страницы.
     */
    public static function clearHomeCache(): void
    {
        Cache::forget(self::CACHE_KEY_SELECTIONS);
        Cache::forget(self::CACHE_KEY_NEW . '.10');
        Cache::forget(self::CACHE_KEY_BESTSELLERS . '.10');
    }
}
