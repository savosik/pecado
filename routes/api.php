<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\User\CatalogApiController;
use App\Http\Controllers\User\CatalogController as UserCatalogController;
use Illuminate\Support\Facades\Route;

// Поисковые подсказки (Autocomplete)
Route::get('/search/suggestions', [CatalogController::class, 'suggestions'])->name('api.search.suggestions');

// Каталог-панель (категории + бренды)
Route::get('/catalog/categories', [UserCatalogController::class, 'categories'])->name('api.catalog.categories');
Route::get('/catalog/brands', [UserCatalogController::class, 'brands'])->name('api.catalog.brands');

// Каталог товаров: список, фасеты, ценовые интервалы
Route::get('/catalog/products', [CatalogApiController::class, 'products'])->name('api.catalog.products');
Route::get('/catalog/products/facets', [CatalogApiController::class, 'facets'])->name('api.catalog.products.facets');
Route::get('/catalog/products/price-intervals', [CatalogApiController::class, 'priceIntervals'])->name('api.catalog.products.price-intervals');
