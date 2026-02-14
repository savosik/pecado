<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Product\ProductQueryService;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $selections  = ProductSelectionController::getCachedSelections();
        $newProducts = ProductSelectionController::getCachedNewProducts(10);
        $bestsellers = ProductSelectionController::getCachedBestsellerProducts(10);

        // Обогащаем остатками по региону текущего пользователя
        $selections  = ProductQueryService::enrichSelectionsWithStock($selections);
        $newProducts = ProductQueryService::enrichProductsWithStock($newProducts);
        $bestsellers = ProductQueryService::enrichProductsWithStock($bestsellers);

        // Обогащаем скидками (enrichSelectionsWithDiscounts загружает скидки одним запросом)
        $selections  = ProductQueryService::enrichSelectionsWithDiscounts($selections);
        $newProducts = ProductQueryService::enrichProductsWithDiscounts($newProducts);
        $bestsellers = ProductQueryService::enrichProductsWithDiscounts($bestsellers);

        // Конвертируем валюту
        $selections  = ProductQueryService::convertSelectionsPrices($selections);
        $newProducts = ProductQueryService::convertProductsPrices($newProducts);
        $bestsellers = ProductQueryService::convertProductsPrices($bestsellers);

        return Inertia::render('User/Home', [
            'banners'            => BannerController::getCachedBanners(),
            'stories'            => StoryController::getCachedStories(),
            'productSelections'  => $selections,
            'newProducts'        => $newProducts,
            'bestsellerProducts' => $bestsellers,
            'seo'                => [
                'title'       => 'Pecado — Интернет-магазин для взрослых',
                'description' => 'Pecado — широкий ассортимент товаров для взрослых. Доставка по всей России.',
                'url'         => url('/'),
                'type'        => 'website',
            ],
        ]);
    }
}
