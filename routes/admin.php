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
    
    
    // Каталог
    Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
    Route::resource('products', ProductController::class);
    Route::delete('/products/{product}/media', [ProductController::class, 'deleteMedia'])->name('products.media.delete');
    
    Route::get('/categories/attributes', [CategoryController::class, 'attributes'])->name('categories.attributes');
    Route::get('/categories/search', [CategoryController::class, 'search'])->name('categories.search');
    Route::resource('categories', CategoryController::class);
    Route::delete('/categories/{category}/media', [CategoryController::class, 'deleteMedia'])->name('categories.media.delete');

    // Бренды
    Route::get('/brands/search', [\App\Http\Controllers\Admin\BrandController::class, 'search'])->name('brands.search');
    Route::resource('brands', \App\Http\Controllers\Admin\BrandController::class);
    Route::delete('/brands/{brand}/media', [\App\Http\Controllers\Admin\BrandController::class, 'deleteMedia'])->name('brands.media.delete');
    
    // Модели товаров
    Route::get('/product-models/search', [\App\Http\Controllers\Admin\ProductModelController::class, 'search'])->name('product-models.search');
    Route::resource('product-models', \App\Http\Controllers\Admin\ProductModelController::class);
    
    // Атрибуты
    Route::resource('attributes', \App\Http\Controllers\Admin\AttributeController::class);
    
    // Группы атрибутов
    Route::get('/attribute-groups/search', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'search'])->name('attribute-groups.search');
    Route::resource('attribute-groups', \App\Http\Controllers\Admin\AttributeGroupController::class);
    
    // Размерные сетки
    Route::resource('size-charts', \App\Http\Controllers\Admin\SizeChartController::class);
    
    // Штрихкоды
    Route::resource('product-barcodes', \App\Http\Controllers\Admin\ProductBarcodeController::class);
    
    // Сертификаты
    Route::get('/certificates/search', [\App\Http\Controllers\Admin\CertificateController::class, 'search'])->name('certificates.search');
    Route::resource('certificates', \App\Http\Controllers\Admin\CertificateController::class);
    
    // Выгрузки товаров
    Route::post('/product-exports/preview', [\App\Http\Controllers\Admin\ProductExportController::class, 'preview'])->name('product-exports.preview');
    Route::get('/product-exports/filter-options', [\App\Http\Controllers\Admin\ProductExportController::class, 'filterOptions'])->name('product-exports.filter-options');
    Route::resource('product-exports', \App\Http\Controllers\Admin\ProductExportController::class)->except(['show']);

    
    // Теги
    Route::get('/tags/search', [\App\Http\Controllers\Admin\TagController::class, 'search'])->name('tags.search');
    Route::resource('tags', \App\Http\Controllers\Admin\TagController::class);
    
    // Настройки
    Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update');
    
    // Страницы
    Route::get('/pages/search', [\App\Http\Controllers\Admin\PageController::class, 'search'])->name('pages.search');
    Route::resource('pages', \App\Http\Controllers\Admin\PageController::class);
    
    // Баннеры
    Route::get('/banners/search', [\App\Http\Controllers\Admin\BannerController::class, 'search'])->name('banners.search');
    Route::resource('banners', \App\Http\Controllers\Admin\BannerController::class);
    
    // Медиа
    Route::get('/media', [\App\Http\Controllers\Admin\MediaController::class, 'index'])->name('media.index');
    Route::delete('/media/{media}', [\App\Http\Controllers\Admin\MediaController::class, 'destroy'])->name('media.destroy');
    
    // Сторис
    Route::get('/stories/search', [\App\Http\Controllers\Admin\StoryController::class, 'search'])->name('stories.search');
    Route::resource('stories', \App\Http\Controllers\Admin\StoryController::class);
    
    // Слайды сторис (nested resource)
    Route::post('/stories/{story}/slides', [\App\Http\Controllers\Admin\StorySlideController::class, 'store'])->name('stories.slides.store');
    Route::put('/stories/{story}/slides/{slide}', [\App\Http\Controllers\Admin\StorySlideController::class, 'update'])->name('stories.slides.update');
    Route::delete('/stories/{story}/slides/{slide}', [\App\Http\Controllers\Admin\StorySlideController::class, 'destroy'])->name('stories.slides.destroy');
    Route::put('/stories/{story}/slides-reorder', [\App\Http\Controllers\Admin\StorySlideController::class, 'reorder'])->name('stories.slides.reorder');
    
    // Склады
    Route::get('/warehouses/search', [\App\Http\Controllers\Admin\WarehouseController::class, 'search'])->name('warehouses.search');
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
    Route::get('/returns/search-products', [\App\Http\Controllers\Admin\ReturnController::class, 'searchProducts'])->name('returns.search-products');
    Route::get('/returns/product-orders', [\App\Http\Controllers\Admin\ReturnController::class, 'getProductOrders'])->name('returns.product-orders');
    Route::patch('/returns/{return}/status', [\App\Http\Controllers\Admin\ReturnController::class, 'updateStatus'])->name('returns.status');
    Route::patch('/returns/{return}/admin-comment', [\App\Http\Controllers\Admin\ReturnController::class, 'updateAdminComment'])->name('returns.admin-comment');
    Route::post('/returns/bulk-status', [\App\Http\Controllers\Admin\ReturnController::class, 'bulkUpdateStatus'])->name('returns.bulk-status');
    Route::resource('returns', \App\Http\Controllers\Admin\ReturnController::class);
    
    // Избранное
    Route::post('/favorites/bulk-delete', [\App\Http\Controllers\Admin\FavoriteController::class, 'bulkDelete'])->name('favorites.bulk-delete');
    Route::get('/favorites/search-users', [\App\Http\Controllers\Admin\FavoriteController::class, 'searchUsers'])->name('favorites.search-users');
    Route::resource('favorites', \App\Http\Controllers\Admin\FavoriteController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    
    // Список желаний
    Route::post('/wishlist/bulk-delete', [\App\Http\Controllers\Admin\WishlistController::class, 'bulkDelete'])->name('wishlist.bulk-delete');
    Route::get('/wishlist/search-users', [\App\Http\Controllers\Admin\WishlistController::class, 'searchUsers'])->name('wishlist.search-users');
    Route::resource('wishlist', \App\Http\Controllers\Admin\WishlistController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

    // --------------------
    // Маркетинг (Marketing)
    // --------------------

    // Акции
    Route::get('/promotions/search', [\App\Http\Controllers\Admin\PromotionController::class, 'search'])->name('promotions.search');
    Route::delete('/promotions/{promotion}/media', [\App\Http\Controllers\Admin\PromotionController::class, 'deleteMedia'])->name('promotions.media.delete');
    Route::resource('promotions', \App\Http\Controllers\Admin\PromotionController::class);

    // Скидки
    Route::get('/discounts/search-users', [\App\Http\Controllers\Admin\DiscountController::class, 'searchUsers'])->name('discounts.search-users');
    Route::resource('discounts', \App\Http\Controllers\Admin\DiscountController::class);

    // Подборки товаров
    Route::delete('/product-selections/{product_selection}/media', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'deleteMedia'])->name('product-selections.media.delete');
    Route::resource('product-selections', \App\Http\Controllers\Admin\ProductSelectionController::class);

    // --------------------
    // Пользователи (Users)
    // --------------------

    // Пользователи
    Route::get('/users/search', [\App\Http\Controllers\Admin\UserController::class, 'search'])->name('users.search');
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class);

    // Компании
    Route::get('/companies/search', [\App\Http\Controllers\Admin\CompanyController::class, 'search'])->name('companies.search');
    Route::resource('companies', \App\Http\Controllers\Admin\CompanyController::class);

    // Банковские счета компаний
    Route::get('/company-bank-accounts/search', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'search'])->name('company-bank-accounts.search');
    Route::resource('company-bank-accounts', \App\Http\Controllers\Admin\CompanyBankAccountController::class);

    // Адреса доставки
    Route::get('/delivery-addresses/search', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'search'])->name('delivery-addresses.search');
    Route::resource('delivery-addresses', \App\Http\Controllers\Admin\DeliveryAddressController::class);

    // --------------------
    // Финансы (Finance)
    // --------------------

    // Валюты
    Route::get('/currencies/search', [\App\Http\Controllers\Admin\CurrencyController::class, 'search'])->name('currencies.search');
    Route::post('/currencies/update-rates', [\App\Http\Controllers\Admin\CurrencyController::class, 'updateRates'])->name('currencies.update-rates');
    Route::resource('currencies', \App\Http\Controllers\Admin\CurrencyController::class);

    // Балансы пользователей
    Route::get('/user-balances/search', [\App\Http\Controllers\Admin\UserBalanceController::class, 'search'])->name('user-balances.search');
    Route::resource('user-balances', \App\Http\Controllers\Admin\UserBalanceController::class);

    // --------------------
    // Контент (Content)
    // --------------------

    // Статьи
    Route::get('/articles/search', [\App\Http\Controllers\Admin\ArticleController::class, 'search'])->name('articles.search');
    Route::resource('articles', \App\Http\Controllers\Admin\ArticleController::class);

    // Новости
    Route::get('/news/search', [\App\Http\Controllers\Admin\NewsController::class, 'search'])->name('news.search');
    Route::resource('news', \App\Http\Controllers\Admin\NewsController::class);

    // FAQ
    Route::get('/faqs/search', [\App\Http\Controllers\Admin\FaqController::class, 'search'])->name('faqs.search');
    Route::resource('faqs', \App\Http\Controllers\Admin\FaqController::class);
});
