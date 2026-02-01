<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\CategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group with auth and admin middleware.
|
*/

Route::middleware(['web', 'auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    
    // Components Demo (для тестирования Phase 3)
    Route::get('/components-demo', function () {
        return inertia('Admin/ComponentsDemo');
    })->name('components-demo');
    
    // Каталог
    Route::resource('products', ProductController::class);
    Route::delete('/products/{product}/media', [ProductController::class, 'deleteMedia'])->name('products.media.delete');
    
    Route::resource('categories', CategoryController::class);
    Route::delete('/categories/{category}/media', [CategoryController::class, 'deleteMedia'])->name('categories.media.delete');

    // Бренды
    Route::resource('brands', \App\Http\Controllers\Admin\BrandController::class);
    Route::delete('/brands/{brand}/media', [\App\Http\Controllers\Admin\BrandController::class, 'deleteMedia'])->name('brands.media.delete');
    
    // Модели товаров
    Route::resource('product-models', \App\Http\Controllers\Admin\ProductModelController::class);
    
    // Теги
    Route::get('/tags/search', [\App\Http\Controllers\Admin\TagController::class, 'search'])->name('tags.search');
});
