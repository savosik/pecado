<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ProductSelection;
use Illuminate\Support\Facades\Cache;

class ProductSelectionController extends Controller
{
    /**
     * Получить подборки товаров с кешированием (10 мин).
     */
    public static function getCachedSelections(): array
    {
        return Cache::remember('user.product_selections.active', 600, function () {
            return ProductSelection::active()
                ->showOnHome()
                ->ordered()
                ->with(['featuredProducts' => function ($query) {
                    $query->with(['brand', 'media']);
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
                        'products'          => $selection->featuredProducts->map(function ($product) {
                            return [
                                'id'             => $product->id,
                                'name'           => $product->name,
                                'slug'           => $product->slug,
                                'brand_name'     => $product->brand?->name,
                                'main_image'     => $product->getFirstMediaUrl('main'),
                                'is_new'         => $product->is_new,
                                'is_bestseller'  => $product->is_bestseller,
                            ];
                        })->values()->toArray(),
                    ];
                })
                ->toArray();
        });
    }
}
