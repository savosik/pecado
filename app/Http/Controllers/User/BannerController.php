<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Support\Facades\Cache;

class BannerController extends Controller
{
    /**
     * Получить активные баннеры для публичной части.
     */
    public function index()
    {
        $banners = $this->getCachedBanners();

        return response()->json($banners);
    }

    /**
     * Получить баннеры с кешированием (10 мин).
     */
    public static function getCachedBanners(): array
    {
        return Cache::remember('user.banners.active', 600, function () {
            return Banner::active()
                ->ordered()
                ->with('linkable')
                ->get()
                ->map(function (Banner $banner) {
                    return [
                        'id'               => $banner->id,
                        'title'            => $banner->title,
                        'desktop_image'    => $banner->getFirstMediaUrl('desktop'),
                        'mobile_image'     => $banner->getFirstMediaUrl('mobile'),
                        'link_url'         => self::resolveLinkUrl($banner),
                        'sort_order'       => $banner->sort_order,
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Резолвить URL из полиморфной связи linkable.
     */
    private static function resolveLinkUrl(Banner $banner): ?string
    {
        $linkable = $banner->linkable;

        if (!$linkable) {
            return null;
        }

        $slug = $linkable->slug ?? null;

        if (!$slug) {
            return null;
        }

        return match (get_class($linkable)) {
            \App\Models\Product::class   => "/products/{$slug}",
            \App\Models\Category::class  => "/categories/{$slug}",
            \App\Models\Brand::class     => "/brands/{$slug}",
            \App\Models\Promotion::class => "/promotions/{$slug}",
            \App\Models\News::class      => "/news/{$slug}",
            \App\Models\Article::class   => "/articles/{$slug}",
            \App\Models\Page::class      => "/pages/{$slug}",
            default                      => null,
        };
    }
}
