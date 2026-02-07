<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

// Поисковые подсказки (Autocomplete)
Route::get('/search/suggestions', [CatalogController::class, 'suggestions'])->name('api.search.suggestions');
