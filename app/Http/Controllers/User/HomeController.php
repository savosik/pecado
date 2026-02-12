<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        return Inertia::render('User/Home', [
            'banners'            => BannerController::getCachedBanners(),
            'stories'            => StoryController::getCachedStories(),
            'productSelections'  => ProductSelectionController::getCachedSelections(),
            'seo'                => [
                'title'       => 'Pecado — Интернет-магазин для взрослых',
                'description' => 'Pecado — широкий ассортимент товаров для взрослых. Доставка по всей России.',
                'url'         => url('/'),
                'type'        => 'website',
            ],
        ]);
    }
}
