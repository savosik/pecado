<?php

use App\Http\Controllers\User\HomeController;
use App\Http\Controllers\User\ProductController;
use App\Http\Controllers\User\CabinetController;
use App\Http\Controllers\User\CabinetCartController;
use App\Http\Controllers\User\CompanyController;
use App\Http\Controllers\User\BankAccountController;
use App\Http\Controllers\User\FavoriteController;
use App\Http\Controllers\User\CartController;
use App\Http\Controllers\User\CheckoutController;
use App\Http\Controllers\User\CurrencyController;
use App\Http\Controllers\User\FaqController;
use App\Http\Controllers\User\NewsController;
use App\Http\Controllers\User\ArticleController;
use App\Http\Controllers\User\PageController;
use App\Http\Controllers\User\SearchController;
use App\Http\Controllers\User\OrderController;
use Illuminate\Support\Facades\Route;

// ──────────────────────────────────────────────
// User Public Routes
// ──────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/favorites', [ProductController::class, 'favorites'])->middleware('auth')->name('products.favorites');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/brands/{brand:slug}', [ProductController::class, 'byBrand'])->name('products.brand');
Route::get('/categories/{category:slug}', [ProductController::class, 'byCategory'])->name('products.category');
Route::get('/collections/{selection:slug}', [ProductController::class, 'bySelection'])->name('products.selection');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');
Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('news.show');
Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');

// Избранное (страница)
Route::get('/favorites', [FavoriteController::class, 'index'])->middleware('auth')->name('favorites.index');

// ──────────────────────────────────────────────
// Корзина — Web Routes (Inertia)
// ──────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
    Route::get('/cart/{cart}', [CartController::class, 'show'])->name('cart.show');
    Route::post('/cart/{cart}/switch', [CartController::class, 'switch'])->name('cart.switch');
    Route::patch('/cart/{cart}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/{cart}', [CartController::class, 'destroy'])->name('cart.destroy');

    // Оформление заказа
    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

    // Заказы пользователя
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});

// ──────────────────────────────────────────────
// API — Auth-protected endpoints
// ──────────────────────────────────────────────
Route::prefix('api')->middleware('auth')->group(function () {
    // Избранное
    Route::get('/favorites/ids', [FavoriteController::class, 'ids']);
    Route::post('/favorites/{product}/toggle', [FavoriteController::class, 'toggle']);

    // Корзина
    Route::get('/cart/summary', [CartController::class, 'summary']);
    Route::get('/cart/active-quantities', [CartController::class, 'activeQuantities']);
    Route::post('/cart/set-product-quantity', [CartController::class, 'setProductQuantity']);
    Route::post('/cart/add-product', [CartController::class, 'addProduct']);
    Route::post('/cart/add-by-barcode', [CartController::class, 'addByBarcode']);
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem']);
    Route::post('/cart/move-items', [CartController::class, 'moveItems']);
    Route::delete('/cart/clear', [CartController::class, 'clear']);

    // Корзина — управление (JSON, для header dropdown)
    Route::get('/cart/user-carts', [CartController::class, 'userCarts']);
    Route::post('/cart/carts', [CartController::class, 'apiStore']);
    Route::post('/cart/carts/{cart}/switch', [CartController::class, 'apiSwitch']);
    Route::patch('/cart/carts/{cart}', [CartController::class, 'apiUpdate']);
    Route::delete('/cart/carts/{cart}', [CartController::class, 'apiDestroy']);

    // Валюта
    Route::post('/currency/switch', [CurrencyController::class, 'switch']);

    // История поиска
    Route::get('/search/history', [SearchController::class, 'history']);
    Route::delete('/search/history', [SearchController::class, 'clearHistory']);
    Route::delete('/search/history/{history}', [SearchController::class, 'deleteHistory']);

    // Категории (для breadcrumbs siblings)
    Route::get('/categories', [ProductController::class, 'categoriesRoot']);
    Route::get('/categories/{category}', [ProductController::class, 'categoryShow']);
});

// ──────────────────────────────────────────────
// User Cabinet Routes (authenticated)
// ──────────────────────────────────────────────
Route::middleware('auth')->prefix('cabinet')->name('cabinet.')->group(function () {
    Route::get('/dashboard', [CabinetController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile', [CabinetController::class, 'profile'])->name('profile');
    Route::put('/profile', [CabinetController::class, 'updateProfile'])->name('profile.update');
    Route::get('/change-password', [CabinetController::class, 'changePassword'])->name('password.change');
    Route::put('/change-password', [CabinetController::class, 'updatePassword'])->name('password.update');

    // Companies
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');

    // Bank Accounts (JSON API)
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::put('/bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

    // Carts
    Route::get('/carts', [CabinetCartController::class, 'index'])->name('carts.index');
    Route::get('/carts/search-products', [CabinetCartController::class, 'searchProducts'])->name('carts.search-products');
    Route::get('/carts/{cart}', [CabinetCartController::class, 'show'])->name('carts.show');
    Route::patch('/carts/{cart}/rename', [CabinetCartController::class, 'rename'])->name('carts.rename');
    Route::delete('/carts/{cart}', [CabinetCartController::class, 'destroy'])->name('carts.destroy');
    Route::post('/carts/{cart}/switch', [CabinetCartController::class, 'switchCart'])->name('carts.switch');
});
