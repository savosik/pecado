<?php

use App\Http\Controllers\User\CatalogApiController;
use App\Http\Controllers\User\CatalogController as UserCatalogController;
use App\Http\Controllers\User\ProductController;
use App\Http\Controllers\User\SearchController;
use Illuminate\Support\Facades\Route;

// Поисковые подсказки (Autocomplete)
Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('api.search.suggestions');

// Каталог-панель (категории + бренды)
Route::get('/catalog/categories', [UserCatalogController::class, 'categories'])->name('api.catalog.categories');
Route::get('/catalog/brands', [UserCatalogController::class, 'brands'])->name('api.catalog.brands');
Route::get('/catalog/selections', [UserCatalogController::class, 'selections'])->name('api.catalog.selections');

// Каталог товаров: список, фасеты, ценовые интервалы
Route::get('/catalog/products', [CatalogApiController::class, 'products'])->name('api.catalog.products');
Route::get('/catalog/products/facets', [CatalogApiController::class, 'facets'])->name('api.catalog.products.facets');
Route::get('/catalog/products/price-intervals', [CatalogApiController::class, 'priceIntervals'])->name('api.catalog.products.price-intervals');

// QuickView — JSON-карточка товара
Route::get('/products/{product:slug}', [ProductController::class, 'showJson'])->name('api.products.show');
