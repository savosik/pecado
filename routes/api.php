<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\User\CatalogController as UserCatalogController;
use Illuminate\Support\Facades\Route;

// Поисковые подсказки (Autocomplete)
Route::get('/search/suggestions', [CatalogController::class, 'suggestions'])->name('api.search.suggestions');

// Каталог-панель (категории + бренды)
Route::get('/catalog/categories', [UserCatalogController::class, 'categories'])->name('api.catalog.categories');
Route::get('/catalog/brands', [UserCatalogController::class, 'brands'])->name('api.catalog.brands');
