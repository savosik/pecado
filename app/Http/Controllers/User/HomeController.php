<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $selections  = ProductSelectionController::getCachedSelections();
        $newProducts = ProductSelectionController::getCachedNewProducts(10);
        $bestsellers = ProductSelectionController::getCachedBestsellerProducts(10);

        // Обогащаем скидками (enrichSelectionsWithDiscounts загружает скидки одним запросом)
        $selections  = ProductSelectionController::enrichSelectionsWithDiscounts($selections);
        $newProducts = ProductSelectionController::enrichProductsWithDiscounts($newProducts);
        $bestsellers = ProductSelectionController::enrichProductsWithDiscounts($bestsellers);

        // Конвертируем валюту
        $selections  = ProductSelectionController::convertSelectionsPrices($selections);
        $newProducts = ProductSelectionController::convertProductsPrices($newProducts);
        $bestsellers = ProductSelectionController::convertProductsPrices($bestsellers);

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
