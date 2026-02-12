<?php

use App\Http\Controllers\User\HomeController;
use App\Http\Controllers\User\ProductController;
use App\Http\Controllers\User\CabinetController;
use App\Http\Controllers\User\FavoriteController;
use App\Http\Controllers\User\CartController;
use App\Http\Controllers\User\CurrencyController;
use App\Http\Controllers\User\FaqController;
use App\Http\Controllers\User\NewsController;
use App\Http\Controllers\User\ArticleController;
use App\Http\Controllers\User\PageController;
use Illuminate\Support\Facades\Route;

// ──────────────────────────────────────────────
// User Public Routes
// ──────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');
Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('news.show');
Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');

// ──────────────────────────────────────────────
// API — Auth-protected endpoints
// ──────────────────────────────────────────────
Route::prefix('api')->middleware('auth')->group(function () {
    // Избранное
    Route::get('/favorites/ids', [FavoriteController::class, 'ids']);
    Route::post('/favorites/{product}', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);

    // Корзина
    Route::get('/cart/summary', [CartController::class, 'summary']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem']);
    Route::delete('/cart/clear', [CartController::class, 'clear']);

    // Валюта
    Route::post('/currency/switch', [CurrencyController::class, 'switch']);
});

// ──────────────────────────────────────────────
// User Cabinet Routes (authenticated)
// ──────────────────────────────────────────────
Route::middleware('auth')->prefix('cabinet')->name('cabinet.')->group(function () {
    Route::get('/dashboard', [CabinetController::class, 'dashboard'])->name('dashboard');
});
