<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Services\Product\ProductQueryService;
use Illuminate\Support\Facades\Cache;

class ProductSelectionController extends Controller
{
    /**
     * Получить подборки товаров с кешированием (10 мин).
     *
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации/остатков.
     * Остатки добавляются после кеша через enrichSelectionsWithStock.
     */
    public static function getCachedSelections(): array
    {
        return Cache::remember('user.product_selections.active', 600, function () {
            return ProductSelection::active()
                ->showOnHome()
                ->ordered()
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
        return Cache::remember("user.products.new.{$limit}", 600, function () use ($limit) {
            $query = Product::where('is_new', true)
                ->with(ProductQueryService::productEagerLoads())
                ->latest()
                ->limit($limit);

            return $query->get()
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
        return Cache::remember("user.products.bestsellers.{$limit}", 600, function () use ($limit) {
            $query = Product::where('is_bestseller', true)
                ->with(ProductQueryService::productEagerLoads())
                ->latest()
                ->limit($limit);

            return $query->get()
                ->map(fn ($p) => ProductQueryService::productToArray($p))
                ->values()
                ->toArray();
        });
    }
}
