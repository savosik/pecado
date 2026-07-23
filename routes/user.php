<?php

use App\Http\Controllers\User\AnalyticsController;
use App\Http\Controllers\User\ArticleController;
use App\Http\Controllers\User\BankAccountController;
use App\Http\Controllers\User\CabinetCartController;
use App\Http\Controllers\User\CabinetController;
use App\Http\Controllers\User\CabinetQuestionsController;
use App\Http\Controllers\User\CartController;
use App\Http\Controllers\User\CertificateController;
use App\Http\Controllers\User\CheckoutController;
use App\Http\Controllers\User\CompanyController;
use App\Http\Controllers\User\DeliveryAddressController;
use App\Http\Controllers\User\FaqController;
use App\Http\Controllers\User\FavoriteController;
use App\Http\Controllers\User\HomeController;
use App\Http\Controllers\User\MediaController;
use App\Http\Controllers\User\NewsController;
use App\Http\Controllers\User\OrderChangeController;
use App\Http\Controllers\User\OrderController;
use App\Http\Controllers\User\PageController;
use App\Http\Controllers\User\ProductController;
use App\Http\Controllers\User\ProductExportController;
use App\Http\Controllers\User\PromotionController;
use App\Http\Controllers\User\ReturnController;
use App\Http\Controllers\User\SearchController;
use App\Http\Controllers\User\ShipmentController;
use App\Http\Controllers\User\SubscriptionController;
use App\Http\Controllers\User\UserQuestionController;
use Illuminate\Support\Facades\Route;

// ──────────────────────────────────────────────
// User Public Routes
// ──────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/novinki', [ProductController::class, 'novelties'])->name('products.novelties');
Route::get('/products/bestsellery', [ProductController::class, 'bestsellers'])->name('products.bestsellers');
Route::get('/products/utsenka', [ProductController::class, 'liquidation'])->name('products.liquidation');
Route::get('/products/favorites', [ProductController::class, 'favorites'])->middleware('auth')->name('products.favorites');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/certificates/{certificate}/files/{media}/download', [CertificateController::class, 'download'])
    ->whereNumber('certificate')->whereNumber('media')->name('certificates.download');
Route::get('/brands', [App\Http\Controllers\User\BrandsController::class, 'index'])->name('brands.index');
Route::get('/brands/{brand:slug}', [ProductController::class, 'byBrand'])->name('products.brand');
Route::get('/categories/{category:slug}', [ProductController::class, 'byCategory'])->name('products.category');
Route::get('/collections/{selection:slug}', [ProductController::class, 'bySelection'])->name('products.selection');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');
Route::post('/faq/questions', [UserQuestionController::class, 'store'])
    ->middleware('throttle:5,60')
    ->name('faq.questions.store');
Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('news.show');
Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/brand-stories', [App\Http\Controllers\User\BrandStoryController::class, 'index'])->name('brand-stories.index');
Route::get('/brand-stories/{slug}', [App\Http\Controllers\User\BrandStoryController::class, 'show'])->name('brand-stories.show');
Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
Route::get('/promotions/{slug}', [PromotionController::class, 'show'])->name('promotions.show');
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
    Route::post('/checkout/normalize-stock', [CheckoutController::class, 'normalizeStock'])->name('checkout.normalize-stock');

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
    Route::post('/cart/set-products-quantity', [CartController::class, 'setProductsQuantity']);
    Route::get('/cart/product-quantities/{product}', [CartController::class, 'productQuantities']);
    Route::post('/cart/carts/{cart}/set-product-quantity', [CartController::class, 'setProductQuantityInCart']);
    Route::post('/cart/add-product', [CartController::class, 'addProduct']);
    Route::post('/cart/add-defect', [CartController::class, 'addDefect']);
    Route::post('/cart/set-defect-quantity', [CartController::class, 'setDefectQuantity']);
    Route::post('/cart/add-by-barcode', [CartController::class, 'addByBarcode']);
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem']);
    Route::post('/cart/move-items', [CartController::class, 'moveItems']);
    Route::delete('/cart/clear', [CartController::class, 'clear']);

    // Корзина — импорт заказа (список / файл)
    Route::get('/cart/import-order/template', [CartController::class, 'importOrderTemplate']);
    Route::post('/cart/import-order', [CartController::class, 'importOrder']);
    Route::post('/cart/import-order-file', [CartController::class, 'importOrderFile']);

    // Корзина — управление (JSON, для header dropdown)
    Route::get('/cart/user-carts', [CartController::class, 'userCarts']);
    Route::post('/cart/carts', [CartController::class, 'apiStore']);
    Route::post('/cart/carts/{cart}/switch', [CartController::class, 'apiSwitch']);
    Route::patch('/cart/carts/{cart}', [CartController::class, 'apiUpdate']);
    Route::delete('/cart/carts/{cart}', [CartController::class, 'apiDestroy']);

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
// Публичная отписка по токену из письма (без авторизации).
Route::get('/subscriptions/unsubscribe/{token}', [SubscriptionController::class, 'unsubscribe'])
    ->name('subscriptions.unsubscribe');

Route::middleware(['auth'])->prefix('cabinet')->name('cabinet.')->group(function () {
    Route::get('/dashboard', [CabinetController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile', [CabinetController::class, 'profile'])->name('profile');
    Route::put('/profile', [CabinetController::class, 'updateProfile'])->name('profile.update');
    Route::get('/change-password', [CabinetController::class, 'changePassword'])->name('password.change');
    Route::put('/change-password', [CabinetController::class, 'updatePassword'])->name('password.update');

    // Companies
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::post('/companies/api', [CompanyController::class, 'apiStore'])->name('companies.api-store');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    Route::post('/companies/{company}/toggle-default', [CompanyController::class, 'toggleDefault'])->name('companies.toggle-default');

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

    // Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
    Route::get('/orders/{order}/items/export', [OrderController::class, 'exportItems'])->name('orders.items.export');
    Route::post('/orders/{order}/repeat', [OrderController::class, 'repeat'])->name('orders.repeat');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Изменения заказов (сводная лента)
    Route::get('/order-changes/export', [OrderChangeController::class, 'export'])->name('order-changes.export');
    Route::get('/order-changes', [OrderChangeController::class, 'index'])->name('order-changes.index');

    // Delivery Addresses
    Route::get('/delivery-addresses', [DeliveryAddressController::class, 'index'])->name('delivery-addresses.index');
    Route::get('/delivery-addresses/create', [DeliveryAddressController::class, 'create'])->name('delivery-addresses.create');
    Route::post('/delivery-addresses', [DeliveryAddressController::class, 'store'])->name('delivery-addresses.store');
    Route::get('/delivery-addresses/{deliveryAddress}/edit', [DeliveryAddressController::class, 'edit'])->name('delivery-addresses.edit');
    Route::put('/delivery-addresses/{deliveryAddress}', [DeliveryAddressController::class, 'update'])->name('delivery-addresses.update');
    Route::delete('/delivery-addresses/{deliveryAddress}', [DeliveryAddressController::class, 'destroy'])->name('delivery-addresses.destroy');
    Route::post('/delivery-addresses/{deliveryAddress}/toggle-default', [DeliveryAddressController::class, 'toggleDefault'])->name('delivery-addresses.toggle-default');

    // Product Exports
    Route::post('/product-exports/preview', [ProductExportController::class, 'preview'])->name('product-exports.preview');
    Route::get('/product-exports/filter-options', [ProductExportController::class, 'filterOptions'])->name('product-exports.filter-options');
    Route::get('/product-exports', [ProductExportController::class, 'index'])->name('product-exports.index');
    Route::get('/product-exports/create', [ProductExportController::class, 'create'])->name('product-exports.create');
    Route::post('/product-exports', [ProductExportController::class, 'store'])->name('product-exports.store');
    Route::get('/product-exports/{productExport}/edit', [ProductExportController::class, 'edit'])->name('product-exports.edit');
    Route::put('/product-exports/{productExport}', [ProductExportController::class, 'update'])->name('product-exports.update');
    Route::delete('/product-exports/{productExport}', [ProductExportController::class, 'destroy'])->name('product-exports.destroy');

    // Стандартные выгрузки (пресеты для CMS)
    Route::get('/export-presets', [\App\Http\Controllers\User\ExportPresetController::class, 'index'])->name('export-presets.index');
    Route::get('/export-presets/{preset}/status', [\App\Http\Controllers\User\ExportPresetController::class, 'status'])->name('export-presets.status');
    Route::post('/export-presets/{preset}/generate', [\App\Http\Controllers\User\ExportPresetController::class, 'generate'])->name('export-presets.generate');
    Route::delete('/export-presets/{preset}', [\App\Http\Controllers\User\ExportPresetController::class, 'destroy'])->name('export-presets.destroy');

    // API-токены
    Route::get('/api-tokens', [\App\Http\Controllers\User\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\User\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::post('/api-tokens/{apiToken}/regenerate', [\App\Http\Controllers\User\ApiTokenController::class, 'regenerate'])->name('api-tokens.regenerate');
    Route::delete('/api-tokens/{apiToken}', [\App\Http\Controllers\User\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Возвраты
    Route::get('/returns/search-shipments', [ReturnController::class, 'searchShipments'])->name('returns.search-shipments');
    Route::get('/returns/shipment-items', [ReturnController::class, 'getShipmentItems'])->name('returns.shipment-items');
    Route::get('/returns/export', [ReturnController::class, 'export'])->name('returns.export');
    Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/create', [ReturnController::class, 'create'])->name('returns.create');
    Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::get('/returns/{return}', [ReturnController::class, 'show'])->name('returns.show');

    // Аналитика по реализациям
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/data', [AnalyticsController::class, 'data'])->name('analytics.data');
    Route::get('/analytics/abc-xyz', [AnalyticsController::class, 'abcXyz'])->name('analytics.abc-xyz');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');

    // Отгрузки (реализации из 1С)
    Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
    Route::get('/shipments/export', [ShipmentController::class, 'export'])->name('shipments.export');
    Route::get('/shipments/{shipment}/items/export', [ShipmentController::class, 'exportItems'])->name('shipments.items.export');
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');

    // Мои вопросы (FAQ)
    Route::get('/questions', [CabinetQuestionsController::class, 'index'])->name('questions.index');
    Route::get('/questions/{question}', [CabinetQuestionsController::class, 'show'])->name('questions.show');
    Route::get('/questions/{question}/attachment', [CabinetQuestionsController::class, 'downloadAttachment'])->name('questions.attachment');

    // Медиатека
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::get('/media/api', [MediaController::class, 'api'])->name('media.api');
    Route::get('/media/{media}/download', [MediaController::class, 'download'])->name('media.download');
    Route::post('/media/download-batch', [MediaController::class, 'downloadBatch'])->name('media.download-batch');

    // Подписки на изменения сущностей раздела (email; универсальный CRUD).
    Route::get('/subscriptions/{section}', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/subscriptions/{section}', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');

    // Сохранённые поиски (PR 5.1, за флагом `search-cabinet.presets`).
    // Внутри контроллера выключенный флаг даёт 404 — маршруты остаются
    // зарегистрированными постоянно для предсказуемого роутинга.
    Route::get('/search-presets/{section}', [\App\Http\Controllers\User\SearchPresetController::class, 'index'])->name('search-presets.index');
    Route::post('/search-presets', [\App\Http\Controllers\User\SearchPresetController::class, 'store'])->name('search-presets.store');
    Route::delete('/search-presets/{preset}', [\App\Http\Controllers\User\SearchPresetController::class, 'destroy'])->name('search-presets.destroy');
});
