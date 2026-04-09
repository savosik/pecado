<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PromotionController extends Controller
{
    /**
     * Список акций с пагинацией (12 на страницу).
     */
    public function index(Request $request)
    {
        $promotions = Promotion::query()
            ->orderByDesc('created_at')
            ->forRegion(Auth::user()?->region_id)
            ->paginate(12)
            ->withQueryString();

        $promotions->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'excerpt' => $item->description ? Str::limit(strip_tags($item->description), 160) : null,
                'image' => $item->getFirstMediaUrl('list-item') ?: null,
                'created_at' => $item->created_at?->toISOString(),
            ];
        });

        return Inertia::render('User/Promotions/Index', [
            'promotions' => $promotions,
            'seo' => [
                'title' => 'Акции',
                'description' => 'Актуальные акции и специальные предложения нашего магазина.',
                'url' => $request->url(),
                'type' => 'website',
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Акции'],
            ],
        ]);
    }

    /**
     * Детальная страница акции.
     */
    public function show(Request $request, string $slug)
    {
        $promotion = Promotion::query()
            ->where('slug', $slug)
            ->forRegion(Auth::user()?->region_id)
            ->firstOrFail();

        // Загружаем товары через ProductQueryService (как в Избранном)
        $productIds = $promotion->products()->pluck('products.id')->toArray();
        $productItems = [];

        if (!empty($productIds)) {
            $query = Product::query()
                ->whereIn('products.id', $productIds)
                ->select('products.*')
                ->with(ProductQueryService::productEagerLoads());

            ProductQueryService::withRegionStockSums($query);

            $productItems = $query->get()
                ->map(fn ($product) => ProductQueryService::productToArray($product))
                ->toArray();

            $productItems = ProductQueryService::enrichProductsWithDiscounts($productItems);
            $productItems = ProductQueryService::convertProductsPrices($productItems);
        }

        $sanitizedContent = clean($promotion->description);

        $descriptionText = $promotion->meta_description
            ?: ($promotion->description ? Str::limit(strip_tags($promotion->description), 160) : '');

        $detailImage = $promotion->getFirstMediaUrl('detail-item-desktop');
        $detailMobileImage = $promotion->getFirstMediaUrl('detail-item-mobile');

        return Inertia::render('User/Promotions/Show', [
            'promotion' => [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'slug' => $promotion->slug,
                'content' => $sanitizedContent,
                'image' => $detailImage ?: null,
                'mobile_image' => $detailMobileImage ?: null,
                'created_at' => $promotion->created_at?->toISOString(),
                'gallery' => $promotion->getMedia('gallery')->map(fn ($media) => [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                ]),
                'products' => $productItems,
            ],
            'seo' => [
                'title' => $promotion->meta_title ?: $promotion->name,
                'description' => $descriptionText,
                'url' => $request->url(),
                'type' => 'article',
                'image' => $detailImage ?: null,
            ],
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Акции', 'url' => '/promotions'],
                ['label' => $promotion->name],
            ],
        ]);
    }
}

