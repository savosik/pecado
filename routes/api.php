<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DeliveryAddressController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WishlistItemController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\BannerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::apiResource('news', \App\Http\Controllers\Api\NewsController::class)->only(['index', 'show']);
Route::apiResource('articles', \App\Http\Controllers\Api\ArticleController::class)->only(['index', 'show']);
Route::apiResource('brand-stories', \App\Http\Controllers\Api\BrandStoryController::class)->only(['index', 'show']);
Route::apiResource('faqs', FaqController::class)->only(['index', 'show']);
Route::apiResource('banners', BannerController::class)->only(['index', 'show']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'me']); // Keep for convenience if needed, or remove if client will use /users/{id}
    Route::apiResource('users', UserController::class);
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('delivery-addresses', DeliveryAddressController::class);
    Route::apiResource('favorites', FavoriteController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('wishlist', WishlistItemController::class)->only(['index', 'store', 'destroy']);

    // Cart routes
    Route::apiResource('carts', CartController::class);
    Route::post('carts/{cart}/items', [CartController::class, 'addItem']);
    Route::put('carts/{cart}/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('carts/{cart}/items/{item}', [CartController::class, 'removeItem']);
    Route::post('carts/{cart}/move', [CartController::class, 'moveItems']);

    // Order routes
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/checkout', [OrderController::class, 'checkout']);

    // Return routes
    Route::apiResource('returns', ReturnController::class);

    // Media routes
    Route::apiResource('media', \App\Http\Controllers\Api\MediaController::class)->parameters(['media' => 'media']);
});

