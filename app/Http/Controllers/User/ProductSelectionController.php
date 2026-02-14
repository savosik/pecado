<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Services\CurrencyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProductSelectionController extends Controller
{
    /**
     * Преобразовать продукт в массив для фронтенда.
     *
     * Ожидается, что у товара загружен атрибут `warehouses_sum_product_warehousequantity`
     * через withSum('warehouses', 'product_warehouse.quantity') или аналог.
     */
    public static function productToArray($product): array
    {
        $galleryMedia = $product->getMedia('additional');

        return [
            'id'              => $product->id,
            'name'            => $product->name,
            'slug'            => $product->slug,
            'sku'             => $product->sku,
            'base_price'      => (float) $product->base_price,
            'brand_name'      => $product->brand?->name,
            'brand_slug'      => $product->brand?->slug,
            'main_image'      => $product->getFirstMediaUrl('main') ?: null,
            'thumbnail'       => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main') ?: null,
            'gallery_urls'    => $galleryMedia->map(fn ($m) => [
                'url'   => $m->getUrl(),
                'thumb' => $m->getUrl('thumb') ?: $m->getUrl(),
            ])->values()->toArray(),
            'is_new'          => $product->is_new,
            'is_bestseller'   => $product->is_bestseller,
            'stock_quantity'  => (int) ($product->warehouses_sum_product_warehousequantity ?? 0),
            'tags'            => $product->tags->map(fn ($tag) => [
                'id'   => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'type' => $tag->type,
            ])->values()->toArray(),
        ];
    }

    /**
     * Стандартные eager-загрузки для товаров (без warehouses — используем withSum).
     */
    private static function productEagerLoads(): array
    {
        return ['brand', 'media', 'tags'];
    }

    /**
     * Применить withSum для складов к запросу товаров.
     */
    private static function withStockSum($query)
    {
        return $query->withSum('warehouses', 'product_warehouse.quantity');
    }

    /**
     * Получить подборки товаров с кешированием (10 мин).
     *
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации.
     */
    public static function getCachedSelections(): array
    {
        return Cache::remember('user.product_selections.active', 600, function () {
            return ProductSelection::active()
                ->showOnHome()
                ->ordered()
                ->with(['featuredProducts' => function ($query) {
                    $query->with(self::productEagerLoads());
                    self::withStockSum($query);
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
                            ->map(fn ($p) => self::productToArray($p))
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
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации.
     */
    public static function getCachedNewProducts(int $limit = 10): array
    {
        return Cache::remember("user.products.new.{$limit}", 600, function () use ($limit) {
            $query = Product::where('is_new', true)
                ->with(self::productEagerLoads())
                ->latest()
                ->limit($limit);
            self::withStockSum($query);

            return $query->get()
                ->map(fn ($p) => self::productToArray($p))
                ->values()
                ->toArray();
        });
    }

    /**
     * Бестселлеры (is_bestseller = true), кеш 10 мин.
     *
     * Кеш не привязан к пользователю — хранятся «чистые» данные без скидок/конвертации.
     */
    public static function getCachedBestsellerProducts(int $limit = 10): array
    {
        return Cache::remember("user.products.bestsellers.{$limit}", 600, function () use ($limit) {
            $query = Product::where('is_bestseller', true)
                ->with(self::productEagerLoads())
                ->latest()
                ->limit($limit);
            self::withStockSum($query);

            return $query->get()
                ->map(fn ($p) => self::productToArray($p))
                ->values()
                ->toArray();
        });
    }

    /**
     * Загрузить карту скидок текущего пользователя для переданных ID товаров.
     *
     * @param  int[]  $productIds
     * @return Collection  Коллекция [product_id => max_percentage]
     */
    private static function loadDiscountMap(array $productIds): Collection
    {
        $user = Auth::user();
        if (!$user || empty($productIds)) {
            return collect();
        }

        return Discount::where('is_posted', true)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds))
            ->with(['products' => fn ($q) => $q->whereIn('products.id', $productIds)])
            ->get()
            ->flatMap(function ($discount) {
                return $discount->products->map(fn ($product) => [
                    'product_id' => $product->id,
                    'percentage' => $discount->percentage,
                ]);
            })
            // Если несколько скидок на один товар — берём максимальную
            ->groupBy('product_id')
            ->map(fn ($group) => $group->max('percentage'));
    }

    /**
     * Обогатить массив товаров скидками из переданной карты.
     */
    private static function applyDiscountMap(array $products, Collection $discountMap): array
    {
        if ($discountMap->isEmpty()) {
            return $products;
        }

        return array_map(function ($product) use ($discountMap) {
            $percentage = $discountMap[$product['id']] ?? null;
            if ($percentage) {
                $product['discount_percentage'] = $percentage;
                $product['sale_price'] = round($product['base_price'] * (1 - $percentage / 100), 2);
            }
            return $product;
        }, $products);
    }

    /**
     * Обогатить массив товаров скидками текущего пользователя.
     */
    public static function enrichProductsWithDiscounts(array $products, ?Collection $discountMap = null): array
    {
        $user = Auth::user();
        if (!$user) {
            return $products;
        }

        $productIds = collect($products)->pluck('id')->toArray();
        if (empty($productIds)) {
            return $products;
        }

        $discountMap = $discountMap ?? self::loadDiscountMap($productIds);

        return self::applyDiscountMap($products, $discountMap);
    }

    /**
     * Обогатить подборки скидками текущего пользователя.
     * Все ID товаров собираются заранее, скидки загружаются одним запросом.
     */
    public static function enrichSelectionsWithDiscounts(array $selections): array
    {
        $user = Auth::user();
        if (!$user) {
            return $selections;
        }

        // Собираем все ID товаров из всех подборок
        $allProductIds = collect($selections)
            ->flatMap(fn ($sel) => collect($sel['products'] ?? [])->pluck('id'))
            ->unique()
            ->values()
            ->toArray();

        $discountMap = self::loadDiscountMap($allProductIds);

        return array_map(function ($selection) use ($discountMap) {
            $selection['products'] = self::applyDiscountMap($selection['products'] ?? [], $discountMap);
            return $selection;
        }, $selections);
    }

    /**
     * Конвертировать цены товаров в валюту текущего пользователя.
     */
    public static function convertProductsPrices(array $products): array
    {
        $user = Auth::user();
        if (!$user || !$user->currency) {
            return $products;
        }

        $currency = $user->currency;
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
