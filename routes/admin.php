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
    Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
    Route::resource('products', ProductController::class);
    Route::delete('/products/{product}/media', [ProductController::class, 'deleteMedia'])->name('products.media.delete');
    
    Route::resource('categories', CategoryController::class);
    Route::delete('/categories/{category}/media', [CategoryController::class, 'deleteMedia'])->name('categories.media.delete');

    // Бренды
    Route::resource('brands', \App\Http\Controllers\Admin\BrandController::class);
    Route::delete('/brands/{brand}/media', [\App\Http\Controllers\Admin\BrandController::class, 'deleteMedia'])->name('brands.media.delete');
    
    // Модели товаров
    Route::resource('product-models', \App\Http\Controllers\Admin\ProductModelController::class);
    
    // Атрибуты
    Route::resource('attributes', \App\Http\Controllers\Admin\AttributeController::class);
    
    // Размерные сетки
    Route::resource('size-charts', \App\Http\Controllers\Admin\SizeChartController::class);
    
    // Штрихкоды
    Route::resource('product-barcodes', \App\Http\Controllers\Admin\ProductBarcodeController::class);
    
    // Сертификаты
    Route::resource('certificates', \App\Http\Controllers\Admin\CertificateController::class);
    

    
    // Теги
    Route::get('/tags/search', [\App\Http\Controllers\Admin\TagController::class, 'search'])->name('tags.search');
    
    // Склады
    Route::resource('warehouses', \App\Http\Controllers\Admin\WarehouseController::class);
    
    // Регионы
    Route::resource('regions', \App\Http\Controllers\Admin\RegionController::class);

    // --------------------
    // Продажи (Sales)
    // --------------------
    
    // Заказы - полный resource + bulk operations
    Route::post('/orders/bulk-status', [\App\Http\Controllers\Admin\OrderController::class, 'bulkUpdateStatus'])->name('orders.bulk-status');
    Route::post('/orders/calculate-price', [\App\Http\Controllers\Admin\OrderController::class, 'calculatePrice'])->name('orders.calculate-price');
    Route::get('/orders/search-users', [\App\Http\Controllers\Admin\OrderController::class, 'searchUsers'])->name('orders.search-users');
    Route::get('/orders/search-companies', [\App\Http\Controllers\Admin\OrderController::class, 'searchCompanies'])->name('orders.search-companies');
    Route::resource('orders', \App\Http\Controllers\Admin\OrderController::class);
    
    // Корзины
    Route::post('/carts/bulk-delete', [\App\Http\Controllers\Admin\CartController::class, 'bulkDestroy'])->name('carts.bulk-delete');
    Route::post('/carts/calculate-price', [\App\Http\Controllers\Admin\CartController::class, 'calculatePrice'])->name('carts.calculate-price');
    Route::get('/carts/search-users', [\App\Http\Controllers\Admin\CartController::class, 'searchUsers'])->name('carts.search-users');
    Route::post('/carts/{cart}/clear', [\App\Http\Controllers\Admin\CartController::class, 'clearItems'])->name('carts.clear');
    Route::delete('/carts/{cart}/items/{item}', [\App\Http\Controllers\Admin\CartController::class, 'removeItem'])->name('carts.items.destroy');
    Route::put('/carts/{cart}/items/{item}', [\App\Http\Controllers\Admin\CartController::class, 'updateItemQuantity'])->name('carts.items.update');
    Route::resource('carts', \App\Http\Controllers\Admin\CartController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    
    // Возвраты
    Route::patch('/returns/{return}/status', [\App\Http\Controllers\Admin\ReturnController::class, 'updateStatus'])->name('returns.status');
    Route::patch('/returns/{return}/admin-comment', [\App\Http\Controllers\Admin\ReturnController::class, 'updateAdminComment'])->name('returns.admin-comment');
    Route::post('/returns/bulk-status', [\App\Http\Controllers\Admin\ReturnController::class, 'bulkUpdateStatus'])->name('returns.bulk-status');
    Route::resource('returns', \App\Http\Controllers\Admin\ReturnController::class);
    
    // Избранное
    Route::resource('favorites', \App\Http\Controllers\Admin\FavoriteController::class)->only(['index', 'destroy']);
    
    // Список желаний
    Route::resource('wishlist', \App\Http\Controllers\Admin\WishlistController::class)->only(['index', 'destroy']);
});
