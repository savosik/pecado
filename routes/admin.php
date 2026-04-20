<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Все маршруты админки защищены middleware 'admin' (проверка наличия роли)
| + явный middleware 'permission:resource.action' на каждой группе.
|
*/

Route::middleware(['web', 'auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard — доступен всем с ролью
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Массовое удаление всех записей раздела (фоновое)
    Route::delete('/bulk-delete-all/{resource}', [\App\Http\Controllers\Admin\BulkDeleteController::class, 'destroyAll'])->name('bulk-delete-all');
    Route::get('/bulk-delete-status/{resource}', [\App\Http\Controllers\Admin\BulkDeleteController::class, 'status'])->name('bulk-delete-status');

    // Массовое окончательное удаление soft-deleted записей (фоновое)
    Route::delete('/bulk-force-delete-all/{resource}', [\App\Http\Controllers\Admin\BulkDeleteController::class, 'forceDestroyAll'])->name('bulk-force-delete-all');
    Route::get('/bulk-force-delete-status/{resource}', [\App\Http\Controllers\Admin\BulkDeleteController::class, 'forceStatus'])->name('bulk-force-delete-status');

    // Editor.js — загрузка изображений
    Route::post('/api/upload-image', [\App\Http\Controllers\Admin\EditorUploadController::class, 'uploadByFile'])->name('api.upload-image');
    Route::post('/api/fetch-url', [\App\Http\Controllers\Admin\EditorUploadController::class, 'fetchByUrl'])->name('api.fetch-url');

    // Editor.js — AI-генерация блоков
    Route::post('/api/ai-generate-block', [\App\Http\Controllers\Admin\AiBlockController::class, 'generate'])->name('api.ai-generate-block');

    // =====================================================================
    // Каталог
    // =====================================================================

    Route::middleware('permission:products.view')->group(function () {
        Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    });
    Route::middleware('permission:products.create')->group(function () {
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    });
    Route::middleware('permission:products.edit')->group(function () {
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}', [ProductController::class, 'update']);
    });
    Route::middleware('permission:products.delete')->group(function () {
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::delete('/products/{product}/media', [ProductController::class, 'deleteMedia'])->name('products.media.delete');
    });

    Route::middleware('permission:categories.view')->group(function () {
        Route::get('/categories/attributes', [CategoryController::class, 'attributes'])->name('categories.attributes');
        Route::get('/categories/search', [CategoryController::class, 'search'])->name('categories.search');
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    });
    Route::middleware('permission:categories.create')->group(function () {
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    });
    Route::middleware('permission:categories.edit')->group(function () {
        Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    });
    Route::middleware('permission:categories.delete')->group(function () {
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::delete('/categories/{category}/media', [CategoryController::class, 'deleteMedia'])->name('categories.media.delete');
    });

    Route::middleware('permission:brands.view')->group(function () {
        Route::get('/brands/search', [\App\Http\Controllers\Admin\BrandController::class, 'search'])->name('brands.search');
        Route::get('/brands', [\App\Http\Controllers\Admin\BrandController::class, 'index'])->name('brands.index');
        Route::get('/brands/{brand}', [\App\Http\Controllers\Admin\BrandController::class, 'show'])->name('brands.show');
    });
    Route::middleware('permission:brands.create')->group(function () {
        Route::get('/brands/create', [\App\Http\Controllers\Admin\BrandController::class, 'create'])->name('brands.create');
        Route::post('/brands', [\App\Http\Controllers\Admin\BrandController::class, 'store'])->name('brands.store');
    });
    Route::middleware('permission:brands.edit')->group(function () {
        Route::get('/brands/{brand}/edit', [\App\Http\Controllers\Admin\BrandController::class, 'edit'])->name('brands.edit');
        Route::put('/brands/{brand}', [\App\Http\Controllers\Admin\BrandController::class, 'update'])->name('brands.update');
    });
    Route::middleware('permission:brands.delete')->group(function () {
        Route::delete('/brands/{brand}', [\App\Http\Controllers\Admin\BrandController::class, 'destroy'])->name('brands.destroy');
        Route::delete('/brands/{brand}/media', [\App\Http\Controllers\Admin\BrandController::class, 'deleteMedia'])->name('brands.media.delete');
    });

    // Модели товаров
    Route::middleware('permission:product-models.view')->group(function () {
        Route::get('/product-models/search', [\App\Http\Controllers\Admin\ProductModelController::class, 'search'])->name('product-models.search');
        Route::get('/product-models', [\App\Http\Controllers\Admin\ProductModelController::class, 'index'])->name('product-models.index');
    });
    Route::middleware('permission:product-models.create')->group(function () {
        Route::get('/product-models/create', [\App\Http\Controllers\Admin\ProductModelController::class, 'create'])->name('product-models.create');
        Route::post('/product-models', [\App\Http\Controllers\Admin\ProductModelController::class, 'store'])->name('product-models.store');
    });
    Route::middleware('permission:product-models.edit')->group(function () {
        Route::get('/product-models/{product_model}/edit', [\App\Http\Controllers\Admin\ProductModelController::class, 'edit'])->name('product-models.edit');
        Route::put('/product-models/{product_model}', [\App\Http\Controllers\Admin\ProductModelController::class, 'update'])->name('product-models.update');
    });
    Route::delete('/product-models/{product_model}', [\App\Http\Controllers\Admin\ProductModelController::class, 'destroy'])->name('product-models.destroy')->middleware('permission:product-models.delete');

    // Атрибуты
    Route::middleware('permission:attributes.view')->get('/attributes', [\App\Http\Controllers\Admin\AttributeController::class, 'index'])->name('attributes.index');
    Route::middleware('permission:attributes.create')->group(function () {
        Route::get('/attributes/create', [\App\Http\Controllers\Admin\AttributeController::class, 'create'])->name('attributes.create');
        Route::post('/attributes', [\App\Http\Controllers\Admin\AttributeController::class, 'store'])->name('attributes.store');
    });
    Route::middleware('permission:attributes.edit')->group(function () {
        Route::get('/attributes/{attribute}/edit', [\App\Http\Controllers\Admin\AttributeController::class, 'edit'])->name('attributes.edit');
        Route::put('/attributes/{attribute}', [\App\Http\Controllers\Admin\AttributeController::class, 'update'])->name('attributes.update');
    });
    Route::delete('/attributes/{attribute}', [\App\Http\Controllers\Admin\AttributeController::class, 'destroy'])->name('attributes.destroy')->middleware('permission:attributes.delete');

    // Группы атрибутов
    Route::middleware('permission:attribute-groups.view')->group(function () {
        Route::get('/attribute-groups/search', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'search'])->name('attribute-groups.search');
        Route::get('/attribute-groups', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'index'])->name('attribute-groups.index');
    });
    Route::middleware('permission:attribute-groups.create')->group(function () {
        Route::get('/attribute-groups/create', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'create'])->name('attribute-groups.create');
        Route::post('/attribute-groups', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'store'])->name('attribute-groups.store');
    });
    Route::middleware('permission:attribute-groups.edit')->group(function () {
        Route::get('/attribute-groups/{attribute_group}/edit', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'edit'])->name('attribute-groups.edit');
        Route::put('/attribute-groups/{attribute_group}', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'update'])->name('attribute-groups.update');
    });
    Route::delete('/attribute-groups/{attribute_group}', [\App\Http\Controllers\Admin\AttributeGroupController::class, 'destroy'])->name('attribute-groups.destroy')->middleware('permission:attribute-groups.delete');

    // Размерные сетки
    Route::middleware('permission:size-charts.view')->get('/size-charts', [\App\Http\Controllers\Admin\SizeChartController::class, 'index'])->name('size-charts.index');
    Route::middleware('permission:size-charts.create')->group(function () {
        Route::get('/size-charts/create', [\App\Http\Controllers\Admin\SizeChartController::class, 'create'])->name('size-charts.create');
        Route::post('/size-charts', [\App\Http\Controllers\Admin\SizeChartController::class, 'store'])->name('size-charts.store');
    });
    Route::middleware('permission:size-charts.edit')->group(function () {
        Route::get('/size-charts/{size_chart}/edit', [\App\Http\Controllers\Admin\SizeChartController::class, 'edit'])->name('size-charts.edit');
        Route::put('/size-charts/{size_chart}', [\App\Http\Controllers\Admin\SizeChartController::class, 'update'])->name('size-charts.update');
    });
    Route::delete('/size-charts/{size_chart}', [\App\Http\Controllers\Admin\SizeChartController::class, 'destroy'])->name('size-charts.destroy')->middleware('permission:size-charts.delete');

    // Штрихкоды
    Route::middleware('permission:product-barcodes.view')->get('/product-barcodes', [\App\Http\Controllers\Admin\ProductBarcodeController::class, 'index'])->name('product-barcodes.index');
    Route::middleware('permission:product-barcodes.create')->group(function () {
        Route::get('/product-barcodes/create', [\App\Http\Controllers\Admin\ProductBarcodeController::class, 'create'])->name('product-barcodes.create');
        Route::post('/product-barcodes', [\App\Http\Controllers\Admin\ProductBarcodeController::class, 'store'])->name('product-barcodes.store');
    });
    Route::middleware('permission:product-barcodes.edit')->group(function () {
        Route::get('/product-barcodes/{product_barcode}/edit', [\App\Http\Controllers\Admin\ProductBarcodeController::class, 'edit'])->name('product-barcodes.edit');
        Route::put('/product-barcodes/{product_barcode}', [\App\Http\Controllers\Admin\ProductBarcodeController::class, 'update'])->name('product-barcodes.update');
    });
    Route::delete('/product-barcodes/{product_barcode}', [\App\Http\Controllers\Admin\ProductBarcodeController::class, 'destroy'])->name('product-barcodes.destroy')->middleware('permission:product-barcodes.delete');

    // Сертификаты
    Route::middleware('permission:certificates.view')->group(function () {
        Route::get('/certificates/search', [\App\Http\Controllers\Admin\CertificateController::class, 'search'])->name('certificates.search');
        Route::get('/certificates', [\App\Http\Controllers\Admin\CertificateController::class, 'index'])->name('certificates.index');
    });
    Route::middleware('permission:certificates.create')->group(function () {
        Route::get('/certificates/create', [\App\Http\Controllers\Admin\CertificateController::class, 'create'])->name('certificates.create');
        Route::post('/certificates', [\App\Http\Controllers\Admin\CertificateController::class, 'store'])->name('certificates.store');
    });
    Route::middleware('permission:certificates.edit')->group(function () {
        Route::get('/certificates/{certificate}/edit', [\App\Http\Controllers\Admin\CertificateController::class, 'edit'])->name('certificates.edit');
        Route::put('/certificates/{certificate}', [\App\Http\Controllers\Admin\CertificateController::class, 'update'])->name('certificates.update');
    });
    Route::delete('/certificates/{certificate}', [\App\Http\Controllers\Admin\CertificateController::class, 'destroy'])->name('certificates.destroy')->middleware('permission:certificates.delete');

    // Выгрузки товаров
    Route::middleware('permission:product-exports.view')->group(function () {
        Route::get('/product-exports', [\App\Http\Controllers\Admin\ProductExportController::class, 'index'])->name('product-exports.index');
        Route::get('/product-exports/filter-options', [\App\Http\Controllers\Admin\ProductExportController::class, 'filterOptions'])->name('product-exports.filter-options');
    });
    Route::middleware('permission:product-exports.create')->group(function () {
        Route::get('/product-exports/create', [\App\Http\Controllers\Admin\ProductExportController::class, 'create'])->name('product-exports.create');
        Route::post('/product-exports', [\App\Http\Controllers\Admin\ProductExportController::class, 'store'])->name('product-exports.store');
        Route::post('/product-exports/preview', [\App\Http\Controllers\Admin\ProductExportController::class, 'preview'])->name('product-exports.preview');
    });
    Route::middleware('permission:product-exports.edit')->group(function () {
        Route::get('/product-exports/{product_export}/edit', [\App\Http\Controllers\Admin\ProductExportController::class, 'edit'])->name('product-exports.edit');
        Route::put('/product-exports/{product_export}', [\App\Http\Controllers\Admin\ProductExportController::class, 'update'])->name('product-exports.update');
    });
    Route::delete('/product-exports/{product_export}', [\App\Http\Controllers\Admin\ProductExportController::class, 'destroy'])->name('product-exports.destroy')->middleware('permission:product-exports.delete');

    // =====================================================================
    // Теги
    // =====================================================================
    Route::middleware('permission:tags.view')->group(function () {
        Route::get('/tags/search', [\App\Http\Controllers\Admin\TagController::class, 'search'])->name('tags.search');
        Route::get('/tags', [\App\Http\Controllers\Admin\TagController::class, 'index'])->name('tags.index');
    });
    Route::middleware('permission:tags.create')->group(function () {
        Route::get('/tags/create', [\App\Http\Controllers\Admin\TagController::class, 'create'])->name('tags.create');
        Route::post('/tags', [\App\Http\Controllers\Admin\TagController::class, 'store'])->name('tags.store');
    });
    Route::middleware('permission:tags.edit')->group(function () {
        Route::get('/tags/{tag}/edit', [\App\Http\Controllers\Admin\TagController::class, 'edit'])->name('tags.edit');
        Route::put('/tags/{tag}', [\App\Http\Controllers\Admin\TagController::class, 'update'])->name('tags.update');
    });
    Route::delete('/tags/{tag}', [\App\Http\Controllers\Admin\TagController::class, 'destroy'])->name('tags.destroy')->middleware('permission:tags.delete');

    // =====================================================================
    // Настройки
    // =====================================================================
    Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index')->middleware('permission:settings.view');
    Route::put('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update')->middleware('permission:settings.edit');
    Route::post('/settings/generate-content-token', [\App\Http\Controllers\Admin\SettingsController::class, 'generateContentToken'])->name('settings.generate-content-token')->middleware('permission:settings.edit');

    // =====================================================================
    // Контент
    // =====================================================================

    // Страницы
    Route::middleware('permission:pages.view')->group(function () {
        Route::get('/pages/search', [\App\Http\Controllers\Admin\PageController::class, 'search'])->name('pages.search');
        Route::get('/pages', [\App\Http\Controllers\Admin\PageController::class, 'index'])->name('pages.index');
    });
    Route::middleware('permission:pages.create')->group(function () {
        Route::get('/pages/create', [\App\Http\Controllers\Admin\PageController::class, 'create'])->name('pages.create');
        Route::post('/pages', [\App\Http\Controllers\Admin\PageController::class, 'store'])->name('pages.store');
    });
    Route::middleware('permission:pages.edit')->group(function () {
        Route::get('/pages/{page}/edit', [\App\Http\Controllers\Admin\PageController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}', [\App\Http\Controllers\Admin\PageController::class, 'update'])->name('pages.update');
    });
    Route::delete('/pages/{page}', [\App\Http\Controllers\Admin\PageController::class, 'destroy'])->name('pages.destroy')->middleware('permission:pages.delete');

    // Баннеры
    Route::middleware('permission:banners.view')->group(function () {
        Route::get('/banners/search', [\App\Http\Controllers\Admin\BannerController::class, 'search'])->name('banners.search');
        Route::get('/banners', [\App\Http\Controllers\Admin\BannerController::class, 'index'])->name('banners.index');
    });
    Route::middleware('permission:banners.create')->group(function () {
        Route::get('/banners/create', [\App\Http\Controllers\Admin\BannerController::class, 'create'])->name('banners.create');
        Route::post('/banners', [\App\Http\Controllers\Admin\BannerController::class, 'store'])->name('banners.store');
    });
    Route::middleware('permission:banners.edit')->group(function () {
        Route::get('/banners/{banner}/edit', [\App\Http\Controllers\Admin\BannerController::class, 'edit'])->name('banners.edit');
        Route::put('/banners/{banner}', [\App\Http\Controllers\Admin\BannerController::class, 'update'])->name('banners.update');
    });
    Route::delete('/banners/{banner}', [\App\Http\Controllers\Admin\BannerController::class, 'destroy'])->name('banners.destroy')->middleware('permission:banners.delete');

    // Медиа
    Route::get('/media', [\App\Http\Controllers\Admin\MediaController::class, 'index'])->name('media.index')->middleware('permission:media.view');
    Route::delete('/media/{media}', [\App\Http\Controllers\Admin\MediaController::class, 'destroy'])->name('media.destroy')->middleware('permission:media.delete');

    // Сторис
    Route::middleware('permission:stories.view')->group(function () {
        Route::get('/stories/search', [\App\Http\Controllers\Admin\StoryController::class, 'search'])->name('stories.search');
        Route::get('/stories', [\App\Http\Controllers\Admin\StoryController::class, 'index'])->name('stories.index');
        Route::get('/stories/{story}', [\App\Http\Controllers\Admin\StoryController::class, 'show'])->name('stories.show');
    });
    Route::middleware('permission:stories.create')->group(function () {
        Route::get('/stories/create', [\App\Http\Controllers\Admin\StoryController::class, 'create'])->name('stories.create');
        Route::post('/stories', [\App\Http\Controllers\Admin\StoryController::class, 'store'])->name('stories.store');
    });
    Route::middleware('permission:stories.edit')->group(function () {
        Route::get('/stories/{story}/edit', [\App\Http\Controllers\Admin\StoryController::class, 'edit'])->name('stories.edit');
        Route::put('/stories/{story}', [\App\Http\Controllers\Admin\StoryController::class, 'update'])->name('stories.update');
        // Слайды сторис
        Route::post('/stories/{story}/slides', [\App\Http\Controllers\Admin\StorySlideController::class, 'store'])->name('stories.slides.store');
        Route::put('/stories/{story}/slides/{slide}', [\App\Http\Controllers\Admin\StorySlideController::class, 'update'])->name('stories.slides.update');
        Route::delete('/stories/{story}/slides/{slide}', [\App\Http\Controllers\Admin\StorySlideController::class, 'destroy'])->name('stories.slides.destroy');
        Route::put('/stories/{story}/slides-reorder', [\App\Http\Controllers\Admin\StorySlideController::class, 'reorder'])->name('stories.slides.reorder');
    });
    Route::delete('/stories/{story}', [\App\Http\Controllers\Admin\StoryController::class, 'destroy'])->name('stories.destroy')->middleware('permission:stories.delete');

    // Статьи
    Route::middleware('permission:articles.view')->group(function () {
        Route::get('/articles/search', [\App\Http\Controllers\Admin\ArticleController::class, 'search'])->name('articles.search');
        Route::get('/articles', [\App\Http\Controllers\Admin\ArticleController::class, 'index'])->name('articles.index');
    });
    Route::middleware('permission:articles.create')->group(function () {
        Route::get('/articles/create', [\App\Http\Controllers\Admin\ArticleController::class, 'create'])->name('articles.create');
        Route::post('/articles', [\App\Http\Controllers\Admin\ArticleController::class, 'store'])->name('articles.store');
    });
    Route::middleware('permission:articles.edit')->group(function () {
        Route::get('/articles/{article}/edit', [\App\Http\Controllers\Admin\ArticleController::class, 'edit'])->name('articles.edit');
        Route::put('/articles/{article}', [\App\Http\Controllers\Admin\ArticleController::class, 'update'])->name('articles.update');
    });
    Route::delete('/articles/{article}', [\App\Http\Controllers\Admin\ArticleController::class, 'destroy'])->name('articles.destroy')->middleware('permission:articles.delete');

    // О брендах
    Route::middleware('permission:brand-stories.view')->group(function () {
        Route::get('/brand-stories/search', [\App\Http\Controllers\Admin\BrandStoryController::class, 'search'])->name('brand-stories.search');
        Route::get('/brand-stories', [\App\Http\Controllers\Admin\BrandStoryController::class, 'index'])->name('brand-stories.index');
    });
    Route::middleware('permission:brand-stories.create')->group(function () {
        Route::get('/brand-stories/create', [\App\Http\Controllers\Admin\BrandStoryController::class, 'create'])->name('brand-stories.create');
        Route::post('/brand-stories', [\App\Http\Controllers\Admin\BrandStoryController::class, 'store'])->name('brand-stories.store');
    });
    Route::middleware('permission:brand-stories.edit')->group(function () {
        Route::get('/brand-stories/{brand_story}/edit', [\App\Http\Controllers\Admin\BrandStoryController::class, 'edit'])->name('brand-stories.edit');
        Route::put('/brand-stories/{brand_story}', [\App\Http\Controllers\Admin\BrandStoryController::class, 'update'])->name('brand-stories.update');
    });
    Route::delete('/brand-stories/{brand_story}', [\App\Http\Controllers\Admin\BrandStoryController::class, 'destroy'])->name('brand-stories.destroy')->middleware('permission:brand-stories.delete');

    // Новости
    Route::middleware('permission:news.view')->group(function () {
        Route::get('/news/search', [\App\Http\Controllers\Admin\NewsController::class, 'search'])->name('news.search');
        Route::get('/news', [\App\Http\Controllers\Admin\NewsController::class, 'index'])->name('news.index');
    });
    Route::middleware('permission:news.create')->group(function () {
        Route::get('/news/create', [\App\Http\Controllers\Admin\NewsController::class, 'create'])->name('news.create');
        Route::post('/news', [\App\Http\Controllers\Admin\NewsController::class, 'store'])->name('news.store');
    });
    Route::middleware('permission:news.edit')->group(function () {
        Route::get('/news/{news}/edit', [\App\Http\Controllers\Admin\NewsController::class, 'edit'])->name('news.edit');
        Route::put('/news/{news}', [\App\Http\Controllers\Admin\NewsController::class, 'update'])->name('news.update');
    });
    Route::delete('/news/{news}', [\App\Http\Controllers\Admin\NewsController::class, 'destroy'])->name('news.destroy')->middleware('permission:news.delete');

    // Меню
    Route::middleware('permission:menu-items.view')->group(function () {
        Route::get('/menu-items', [\App\Http\Controllers\Admin\MenuItemController::class, 'index'])->name('menu-items.index');
    });
    Route::middleware('permission:menu-items.create')->group(function () {
        Route::get('/menu-items/create', [\App\Http\Controllers\Admin\MenuItemController::class, 'create'])->name('menu-items.create');
        Route::post('/menu-items', [\App\Http\Controllers\Admin\MenuItemController::class, 'store'])->name('menu-items.store');
    });
    Route::middleware('permission:menu-items.edit')->group(function () {
        Route::get('/menu-items/{menu_item}/edit', [\App\Http\Controllers\Admin\MenuItemController::class, 'edit'])->name('menu-items.edit');
        Route::put('/menu-items/{menu_item}', [\App\Http\Controllers\Admin\MenuItemController::class, 'update'])->name('menu-items.update');
    });
    Route::delete('/menu-items/{menu_item}', [\App\Http\Controllers\Admin\MenuItemController::class, 'destroy'])->name('menu-items.destroy')->middleware('permission:menu-items.delete');

    // FAQ
    Route::middleware('permission:faqs.view')->group(function () {
        Route::get('/faqs/search', [\App\Http\Controllers\Admin\FaqController::class, 'search'])->name('faqs.search');
        Route::get('/faqs', [\App\Http\Controllers\Admin\FaqController::class, 'index'])->name('faqs.index');
    });
    Route::middleware('permission:faqs.create')->group(function () {
        Route::get('/faqs/create', [\App\Http\Controllers\Admin\FaqController::class, 'create'])->name('faqs.create');
        Route::post('/faqs', [\App\Http\Controllers\Admin\FaqController::class, 'store'])->name('faqs.store');
    });
    Route::middleware('permission:faqs.edit')->group(function () {
        Route::get('/faqs/{faq}/edit', [\App\Http\Controllers\Admin\FaqController::class, 'edit'])->name('faqs.edit');
        Route::put('/faqs/{faq}', [\App\Http\Controllers\Admin\FaqController::class, 'update'])->name('faqs.update');
    });
    Route::delete('/faqs/{faq}', [\App\Http\Controllers\Admin\FaqController::class, 'destroy'])->name('faqs.destroy')->middleware('permission:faqs.delete');

    // =====================================================================
    // Склады
    // =====================================================================
    Route::middleware('permission:warehouses.view')->group(function () {
        Route::get('/warehouses/search', [\App\Http\Controllers\Admin\WarehouseController::class, 'search'])->name('warehouses.search');
        Route::get('/warehouses', [\App\Http\Controllers\Admin\WarehouseController::class, 'index'])->name('warehouses.index');
    });
    Route::middleware('permission:warehouses.create')->group(function () {
        Route::get('/warehouses/create', [\App\Http\Controllers\Admin\WarehouseController::class, 'create'])->name('warehouses.create');
        Route::post('/warehouses', [\App\Http\Controllers\Admin\WarehouseController::class, 'store'])->name('warehouses.store');
    });
    Route::middleware('permission:warehouses.edit')->group(function () {
        Route::get('/warehouses/{warehouse}/edit', [\App\Http\Controllers\Admin\WarehouseController::class, 'edit'])->name('warehouses.edit');
        Route::put('/warehouses/{warehouse}', [\App\Http\Controllers\Admin\WarehouseController::class, 'update'])->name('warehouses.update');
    });
    Route::delete('/warehouses/{warehouse}', [\App\Http\Controllers\Admin\WarehouseController::class, 'destroy'])->name('warehouses.destroy')->middleware('permission:warehouses.delete');

    // Регионы
    Route::middleware('permission:regions.view')->get('/regions', [\App\Http\Controllers\Admin\RegionController::class, 'index'])->name('regions.index');
    Route::middleware('permission:regions.create')->group(function () {
        Route::get('/regions/create', [\App\Http\Controllers\Admin\RegionController::class, 'create'])->name('regions.create');
        Route::post('/regions', [\App\Http\Controllers\Admin\RegionController::class, 'store'])->name('regions.store');
    });
    Route::middleware('permission:regions.edit')->group(function () {
        Route::get('/regions/{region}/edit', [\App\Http\Controllers\Admin\RegionController::class, 'edit'])->name('regions.edit');
        Route::put('/regions/{region}', [\App\Http\Controllers\Admin\RegionController::class, 'update'])->name('regions.update');
    });
    Route::delete('/regions/{region}', [\App\Http\Controllers\Admin\RegionController::class, 'destroy'])->name('regions.destroy')->middleware('permission:regions.delete');

    // =====================================================================
    // Продажи
    // =====================================================================

    // Заказы
    Route::middleware('permission:orders.view')->group(function () {
        Route::get('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [\App\Http\Controllers\Admin\OrderController::class, 'show'])->name('orders.show');
    });
    Route::middleware('permission:orders.create')->group(function () {
        Route::get('/orders/create', [\App\Http\Controllers\Admin\OrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/calculate-price', [\App\Http\Controllers\Admin\OrderController::class, 'calculatePrice'])->name('orders.calculate-price');
        Route::get('/orders/search-users', [\App\Http\Controllers\Admin\OrderController::class, 'searchUsers'])->name('orders.search-users');
        Route::get('/orders/search-companies', [\App\Http\Controllers\Admin\OrderController::class, 'searchCompanies'])->name('orders.search-companies');
    });
    Route::middleware('permission:orders.edit')->group(function () {
        Route::get('/orders/{order}/edit', [\App\Http\Controllers\Admin\OrderController::class, 'edit'])->name('orders.edit');
        Route::put('/orders/{order}', [\App\Http\Controllers\Admin\OrderController::class, 'update'])->name('orders.update');
        Route::post('/orders/bulk-status', [\App\Http\Controllers\Admin\OrderController::class, 'bulkUpdateStatus'])->name('orders.bulk-status');
    });
    Route::delete('/orders/{order}', [\App\Http\Controllers\Admin\OrderController::class, 'destroy'])->name('orders.destroy')->middleware('permission:orders.delete');
    Route::delete('/orders/{id}/force-delete', [\App\Http\Controllers\Admin\OrderController::class, 'forceDestroy'])->name('orders.force-delete')->middleware('permission:orders.delete');

    // Корзины
    Route::middleware('permission:carts.view')->get('/carts', [\App\Http\Controllers\Admin\CartController::class, 'index'])->name('carts.index');
    Route::middleware('permission:carts.create')->group(function () {
        Route::get('/carts/create', [\App\Http\Controllers\Admin\CartController::class, 'create'])->name('carts.create');
        Route::post('/carts', [\App\Http\Controllers\Admin\CartController::class, 'store'])->name('carts.store');
        Route::post('/carts/calculate-price', [\App\Http\Controllers\Admin\CartController::class, 'calculatePrice'])->name('carts.calculate-price');
        Route::get('/carts/search-users', [\App\Http\Controllers\Admin\CartController::class, 'searchUsers'])->name('carts.search-users');
    });
    Route::middleware('permission:carts.edit')->group(function () {
        Route::get('/carts/{cart}/edit', [\App\Http\Controllers\Admin\CartController::class, 'edit'])->name('carts.edit');
        Route::put('/carts/{cart}', [\App\Http\Controllers\Admin\CartController::class, 'update'])->name('carts.update');
        Route::put('/carts/{cart}/items/{item}', [\App\Http\Controllers\Admin\CartController::class, 'updateItemQuantity'])->name('carts.items.update');
    });
    Route::middleware('permission:carts.delete')->group(function () {
        Route::delete('/carts/{cart}', [\App\Http\Controllers\Admin\CartController::class, 'destroy'])->name('carts.destroy');
        Route::post('/carts/bulk-delete', [\App\Http\Controllers\Admin\CartController::class, 'bulkDestroy'])->name('carts.bulk-delete');
        Route::post('/carts/{cart}/clear', [\App\Http\Controllers\Admin\CartController::class, 'clearItems'])->name('carts.clear');
        Route::delete('/carts/{cart}/items/{item}', [\App\Http\Controllers\Admin\CartController::class, 'removeItem'])->name('carts.items.destroy');
    });

    // Возвраты
    Route::middleware('permission:returns.view')->group(function () {
        Route::get('/returns', [\App\Http\Controllers\Admin\ReturnController::class, 'index'])->name('returns.index');
    });
    Route::middleware('permission:returns.create')->group(function () {
        Route::get('/returns/create', [\App\Http\Controllers\Admin\ReturnController::class, 'create'])->name('returns.create');
        Route::post('/returns', [\App\Http\Controllers\Admin\ReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/search-products', [\App\Http\Controllers\Admin\ReturnController::class, 'searchProducts'])->name('returns.search-products');
        Route::get('/returns/product-orders', [\App\Http\Controllers\Admin\ReturnController::class, 'getProductOrders'])->name('returns.product-orders');
    });
    Route::middleware('permission:returns.view')->group(function () {
        Route::get('/returns/{return}', [\App\Http\Controllers\Admin\ReturnController::class, 'show'])->name('returns.show');
    });
    Route::middleware('permission:returns.edit')->group(function () {
        Route::post('/returns/bulk-status', [\App\Http\Controllers\Admin\ReturnController::class, 'bulkUpdateStatus'])->name('returns.bulk-status');
        Route::get('/returns/{return}/edit', [\App\Http\Controllers\Admin\ReturnController::class, 'edit'])->name('returns.edit');
        Route::put('/returns/{return}', [\App\Http\Controllers\Admin\ReturnController::class, 'update'])->name('returns.update');
        Route::patch('/returns/{return}/status', [\App\Http\Controllers\Admin\ReturnController::class, 'updateStatus'])->name('returns.status');
        Route::patch('/returns/{return}/admin-comment', [\App\Http\Controllers\Admin\ReturnController::class, 'updateAdminComment'])->name('returns.admin-comment');
    });
    Route::delete('/returns/{return}', [\App\Http\Controllers\Admin\ReturnController::class, 'destroy'])->name('returns.destroy')->middleware('permission:returns.delete');
    Route::delete('/returns/{id}/force-delete', [\App\Http\Controllers\Admin\ReturnController::class, 'forceDestroy'])->name('returns.force-delete')->middleware('permission:returns.delete');

    // Реализации
    Route::middleware('permission:shipments.view')->group(function () {
        Route::get('/shipments', [\App\Http\Controllers\Admin\ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/{shipment}', [\App\Http\Controllers\Admin\ShipmentController::class, 'show'])->name('shipments.show');
    });
    Route::delete('/shipments/{shipment}', [\App\Http\Controllers\Admin\ShipmentController::class, 'destroy'])->name('shipments.destroy')->middleware('permission:shipments.delete');
    Route::delete('/shipments/{id}/force-delete', [\App\Http\Controllers\Admin\ShipmentController::class, 'forceDestroy'])->name('shipments.force-delete')->middleware('permission:shipments.delete');

    // Избранное
    Route::middleware('permission:favorites.view')->group(function () {
        Route::get('/favorites', [\App\Http\Controllers\Admin\FavoriteController::class, 'index'])->name('favorites.index');
        Route::get('/favorites/search-users', [\App\Http\Controllers\Admin\FavoriteController::class, 'searchUsers'])->name('favorites.search-users');
    });
    Route::middleware('permission:favorites.create')->group(function () {
        Route::get('/favorites/create', [\App\Http\Controllers\Admin\FavoriteController::class, 'create'])->name('favorites.create');
        Route::post('/favorites', [\App\Http\Controllers\Admin\FavoriteController::class, 'store'])->name('favorites.store');
    });
    Route::middleware('permission:favorites.edit')->group(function () {
        Route::get('/favorites/{favorite}/edit', [\App\Http\Controllers\Admin\FavoriteController::class, 'edit'])->name('favorites.edit');
        Route::put('/favorites/{favorite}', [\App\Http\Controllers\Admin\FavoriteController::class, 'update'])->name('favorites.update');
    });
    Route::middleware('permission:favorites.delete')->group(function () {
        Route::delete('/favorites/{favorite}', [\App\Http\Controllers\Admin\FavoriteController::class, 'destroy'])->name('favorites.destroy');
        Route::post('/favorites/bulk-delete', [\App\Http\Controllers\Admin\FavoriteController::class, 'bulkDelete'])->name('favorites.bulk-delete');
    });

    // Список желаний
    Route::middleware('permission:wishlist.view')->group(function () {
        Route::get('/wishlist', [\App\Http\Controllers\Admin\WishlistController::class, 'index'])->name('wishlist.index');
        Route::get('/wishlist/search-users', [\App\Http\Controllers\Admin\WishlistController::class, 'searchUsers'])->name('wishlist.search-users');
    });
    Route::middleware('permission:wishlist.create')->group(function () {
        Route::get('/wishlist/create', [\App\Http\Controllers\Admin\WishlistController::class, 'create'])->name('wishlist.create');
        Route::post('/wishlist', [\App\Http\Controllers\Admin\WishlistController::class, 'store'])->name('wishlist.store');
    });
    Route::middleware('permission:wishlist.edit')->group(function () {
        Route::get('/wishlist/{wishlist}/edit', [\App\Http\Controllers\Admin\WishlistController::class, 'edit'])->name('wishlist.edit');
        Route::put('/wishlist/{wishlist}', [\App\Http\Controllers\Admin\WishlistController::class, 'update'])->name('wishlist.update');
    });
    Route::middleware('permission:wishlist.delete')->group(function () {
        Route::delete('/wishlist/{wishlist}', [\App\Http\Controllers\Admin\WishlistController::class, 'destroy'])->name('wishlist.destroy');
        Route::post('/wishlist/bulk-delete', [\App\Http\Controllers\Admin\WishlistController::class, 'bulkDelete'])->name('wishlist.bulk-delete');
    });

    // =====================================================================
    // Маркетинг
    // =====================================================================

    // Акции
    Route::middleware('permission:promotions.view')->group(function () {
        Route::get('/promotions/search', [\App\Http\Controllers\Admin\PromotionController::class, 'search'])->name('promotions.search');
        Route::get('/promotions', [\App\Http\Controllers\Admin\PromotionController::class, 'index'])->name('promotions.index');
    });
    Route::middleware('permission:promotions.create')->group(function () {
        Route::get('/promotions/create', [\App\Http\Controllers\Admin\PromotionController::class, 'create'])->name('promotions.create');
        Route::post('/promotions', [\App\Http\Controllers\Admin\PromotionController::class, 'store'])->name('promotions.store');
    });
    Route::middleware('permission:promotions.edit')->group(function () {
        Route::get('/promotions/{promotion}/edit', [\App\Http\Controllers\Admin\PromotionController::class, 'edit'])->name('promotions.edit');
        Route::put('/promotions/{promotion}', [\App\Http\Controllers\Admin\PromotionController::class, 'update'])->name('promotions.update');
    });
    Route::middleware('permission:promotions.delete')->group(function () {
        Route::delete('/promotions/{promotion}', [\App\Http\Controllers\Admin\PromotionController::class, 'destroy'])->name('promotions.destroy');
        Route::delete('/promotions/{promotion}/media', [\App\Http\Controllers\Admin\PromotionController::class, 'deleteMedia'])->name('promotions.media.delete');
    });

    // Подборки товаров
    Route::middleware('permission:product-selections.view')->get('/product-selections', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'index'])->name('product-selections.index');
    Route::middleware('permission:product-selections.create')->group(function () {
        Route::get('/product-selections/create', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'create'])->name('product-selections.create');
        Route::post('/product-selections', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'store'])->name('product-selections.store');
    });
    Route::middleware('permission:product-selections.edit')->group(function () {
        Route::get('/product-selections/{product_selection}/edit', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'edit'])->name('product-selections.edit');
        Route::put('/product-selections/{product_selection}', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'update'])->name('product-selections.update');
    });
    Route::middleware('permission:product-selections.delete')->group(function () {
        Route::delete('/product-selections/{product_selection}', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'destroy'])->name('product-selections.destroy');
        Route::delete('/product-selections/{product_selection}/media', [\App\Http\Controllers\Admin\ProductSelectionController::class, 'deleteMedia'])->name('product-selections.media.delete');
    });

    // =====================================================================
    // Пользователи
    // =====================================================================

    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users/search', [\App\Http\Controllers\Admin\UserController::class, 'search'])->name('users.search');
        Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
    });
    Route::middleware('permission:users.create')->group(function () {
        Route::get('/users/create', [\App\Http\Controllers\Admin\UserController::class, 'create'])->name('users.create');
        Route::post('/users', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('users.store');
    });
    Route::middleware('permission:users.edit')->group(function () {
        Route::get('/users/{user}/edit', [\App\Http\Controllers\Admin\UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('users.update');
    });
    Route::delete('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('users.destroy')->middleware('permission:users.delete');

    // Компании
    Route::middleware('permission:companies.view')->group(function () {
        Route::get('/companies/search', [\App\Http\Controllers\Admin\CompanyController::class, 'search'])->name('companies.search');
        Route::get('/companies', [\App\Http\Controllers\Admin\CompanyController::class, 'index'])->name('companies.index');
    });
    Route::middleware('permission:companies.create')->group(function () {
        Route::get('/companies/create', [\App\Http\Controllers\Admin\CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [\App\Http\Controllers\Admin\CompanyController::class, 'store'])->name('companies.store');
    });
    Route::middleware('permission:companies.edit')->group(function () {
        Route::get('/companies/{company}/edit', [\App\Http\Controllers\Admin\CompanyController::class, 'edit'])->name('companies.edit');
        Route::put('/companies/{company}', [\App\Http\Controllers\Admin\CompanyController::class, 'update'])->name('companies.update');
    });
    Route::delete('/companies/{company}', [\App\Http\Controllers\Admin\CompanyController::class, 'destroy'])->name('companies.destroy')->middleware('permission:companies.delete');
    Route::delete('/companies/{id}/force-delete', [\App\Http\Controllers\Admin\CompanyController::class, 'forceDestroy'])->name('companies.force-delete')->middleware('permission:companies.delete');

    // Банковские счета
    Route::middleware('permission:company-bank-accounts.view')->group(function () {
        Route::get('/company-bank-accounts/search', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'search'])->name('company-bank-accounts.search');
        Route::get('/company-bank-accounts', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'index'])->name('company-bank-accounts.index');
    });
    Route::middleware('permission:company-bank-accounts.create')->group(function () {
        Route::get('/company-bank-accounts/create', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'create'])->name('company-bank-accounts.create');
        Route::post('/company-bank-accounts', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'store'])->name('company-bank-accounts.store');
    });
    Route::middleware('permission:company-bank-accounts.edit')->group(function () {
        Route::get('/company-bank-accounts/{company_bank_account}/edit', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'edit'])->name('company-bank-accounts.edit');
        Route::put('/company-bank-accounts/{company_bank_account}', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'update'])->name('company-bank-accounts.update');
    });
    Route::delete('/company-bank-accounts/{company_bank_account}', [\App\Http\Controllers\Admin\CompanyBankAccountController::class, 'destroy'])->name('company-bank-accounts.destroy')->middleware('permission:company-bank-accounts.delete');

    // Адреса доставки
    Route::middleware('permission:delivery-addresses.view')->group(function () {
        Route::get('/delivery-addresses/search', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'search'])->name('delivery-addresses.search');
        Route::get('/delivery-addresses', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'index'])->name('delivery-addresses.index');
    });
    Route::middleware('permission:delivery-addresses.create')->group(function () {
        Route::get('/delivery-addresses/create', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'create'])->name('delivery-addresses.create');
        Route::post('/delivery-addresses', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'store'])->name('delivery-addresses.store');
    });
    Route::middleware('permission:delivery-addresses.edit')->group(function () {
        Route::get('/delivery-addresses/{delivery_address}/edit', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'edit'])->name('delivery-addresses.edit');
        Route::put('/delivery-addresses/{delivery_address}', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'update'])->name('delivery-addresses.update');
    });
    Route::delete('/delivery-addresses/{delivery_address}', [\App\Http\Controllers\Admin\DeliveryAddressController::class, 'destroy'])->name('delivery-addresses.destroy')->middleware('permission:delivery-addresses.delete');

    // Анкеты
    Route::middleware('permission:user-questionnaires.view')->get('/user-questionnaires', [\App\Http\Controllers\Admin\UserQuestionnaireController::class, 'index'])->name('user-questionnaires.index');
    Route::middleware('permission:user-questionnaires.create')->group(function () {
        Route::get('/user-questionnaires/create', [\App\Http\Controllers\Admin\UserQuestionnaireController::class, 'create'])->name('user-questionnaires.create');
        Route::post('/user-questionnaires', [\App\Http\Controllers\Admin\UserQuestionnaireController::class, 'store'])->name('user-questionnaires.store');
    });
    Route::middleware('permission:user-questionnaires.edit')->group(function () {
        Route::get('/user-questionnaires/{user_questionnaire}/edit', [\App\Http\Controllers\Admin\UserQuestionnaireController::class, 'edit'])->name('user-questionnaires.edit');
        Route::put('/user-questionnaires/{user_questionnaire}', [\App\Http\Controllers\Admin\UserQuestionnaireController::class, 'update'])->name('user-questionnaires.update');
    });
    Route::delete('/user-questionnaires/{user_questionnaire}', [\App\Http\Controllers\Admin\UserQuestionnaireController::class, 'destroy'])->name('user-questionnaires.destroy')->middleware('permission:user-questionnaires.delete');

    // Статусы клиентов
    Route::middleware('permission:client-statuses.view')->group(function () {
        Route::get('/client-statuses', [\App\Http\Controllers\Admin\ClientStatusController::class, 'index'])->name('client-statuses.index');
    });
    Route::middleware('permission:client-statuses.create')->group(function () {
        Route::get('/client-statuses/create', [\App\Http\Controllers\Admin\ClientStatusController::class, 'create'])->name('client-statuses.create');
        Route::post('/client-statuses', [\App\Http\Controllers\Admin\ClientStatusController::class, 'store'])->name('client-statuses.store');
    });
    Route::middleware('permission:client-statuses.edit')->group(function () {
        Route::get('/client-statuses/{client_status}/edit', [\App\Http\Controllers\Admin\ClientStatusController::class, 'edit'])->name('client-statuses.edit');
        Route::put('/client-statuses/{client_status}', [\App\Http\Controllers\Admin\ClientStatusController::class, 'update'])->name('client-statuses.update');
    });
    Route::middleware('permission:client-statuses.delete')->group(function () {
        Route::delete('/client-statuses/{client_status}', [\App\Http\Controllers\Admin\ClientStatusController::class, 'destroy'])->name('client-statuses.destroy');
        Route::delete('/client-statuses/{client_status}/media', [\App\Http\Controllers\Admin\ClientStatusController::class, 'deleteMedia'])->name('client-statuses.media.delete');
    });

    // =====================================================================
    // Финансы
    // =====================================================================

    // Валюты
    Route::middleware('permission:currencies.view')->group(function () {
        Route::get('/currencies/search', [\App\Http\Controllers\Admin\CurrencyController::class, 'search'])->name('currencies.search');
        Route::get('/currencies', [\App\Http\Controllers\Admin\CurrencyController::class, 'index'])->name('currencies.index');
    });
    Route::middleware('permission:currencies.create')->group(function () {
        Route::get('/currencies/create', [\App\Http\Controllers\Admin\CurrencyController::class, 'create'])->name('currencies.create');
        Route::post('/currencies', [\App\Http\Controllers\Admin\CurrencyController::class, 'store'])->name('currencies.store');
    });
    Route::middleware('permission:currencies.edit')->group(function () {
        Route::get('/currencies/{currency}/edit', [\App\Http\Controllers\Admin\CurrencyController::class, 'edit'])->name('currencies.edit');
        Route::put('/currencies/{currency}', [\App\Http\Controllers\Admin\CurrencyController::class, 'update'])->name('currencies.update');
        Route::post('/currencies/update-rates', [\App\Http\Controllers\Admin\CurrencyController::class, 'updateRates'])->name('currencies.update-rates');
    });
    Route::delete('/currencies/{currency}', [\App\Http\Controllers\Admin\CurrencyController::class, 'destroy'])->name('currencies.destroy')->middleware('permission:currencies.delete');

    // Балансы контрагентов
    Route::middleware('permission:contractor-balances.view')->group(function () {
        Route::get('/contractor-balances/search', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'search'])->name('contractor-balances.search');
        Route::get('/contractor-balances', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'index'])->name('contractor-balances.index');
        Route::get('/contractor-balances/{contractor_balance}', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'show'])->name('contractor-balances.show');
    });
    Route::middleware('permission:contractor-balances.create')->group(function () {
        Route::get('/contractor-balances/create', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'create'])->name('contractor-balances.create');
        Route::post('/contractor-balances', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'store'])->name('contractor-balances.store');
    });
    Route::middleware('permission:contractor-balances.edit')->group(function () {
        Route::get('/contractor-balances/{contractor_balance}/edit', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'edit'])->name('contractor-balances.edit');
        Route::put('/contractor-balances/{contractor_balance}', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'update'])->name('contractor-balances.update');
    });
    Route::delete('/contractor-balances/{contractor_balance}', [\App\Http\Controllers\Admin\ContractorBalanceController::class, 'destroy'])->name('contractor-balances.destroy')->middleware('permission:contractor-balances.delete');

    // Индивидуальные цены
    Route::middleware('permission:individual-prices.view')->group(function () {
        Route::get('/individual-prices', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'index'])->name('individual-prices.index');
        Route::get('/individual-prices/search-partners', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'searchPartners'])->name('individual-prices.search-partners');
        Route::get('/individual-prices/search-products', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'searchProducts'])->name('individual-prices.search-products');
        Route::get('/individual-prices/search-warehouses', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'searchWarehouses'])->name('individual-prices.search-warehouses');
        Route::get('/individual-prices/export', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'export'])->name('individual-prices.export');
    });
    Route::middleware('permission:individual-prices.create')->group(function () {
        Route::get('/individual-prices/create', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'create'])->name('individual-prices.create');
        Route::post('/individual-prices', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'store'])->name('individual-prices.store');
    });
    Route::middleware('permission:individual-prices.edit')->group(function () {
        Route::get('/individual-prices/edit', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'edit'])->name('individual-prices.edit');
        Route::put('/individual-prices', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'update'])->name('individual-prices.update');
    });
    Route::delete('/individual-prices', [\App\Http\Controllers\Admin\IndividualPriceController::class, 'destroy'])->name('individual-prices.destroy')->middleware('permission:individual-prices.delete');

    // =====================================================================
    // Система
    // =====================================================================

    // Канбан
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/kanban', [\App\Http\Controllers\Admin\KanbanController::class, 'index'])->name('kanban.index');
        Route::post('/kanban', [\App\Http\Controllers\Admin\KanbanController::class, 'store'])->name('kanban.store');
        Route::put('/kanban/{kanbanTask}', [\App\Http\Controllers\Admin\KanbanController::class, 'update'])->name('kanban.update');
        Route::put('/kanban/{kanbanTask}/order', [\App\Http\Controllers\Admin\KanbanController::class, 'updateOrder'])->name('kanban.update-order');
        Route::post('/kanban/{kanbanTask}/comments', [\App\Http\Controllers\Admin\KanbanCommentController::class, 'store'])->name('kanban.comments.store');
    });

    // Шина ERP
    Route::get('/erp-bus', [\App\Http\Controllers\Admin\ErpBusController::class, 'index'])->name('erp-bus.index')->middleware('permission:erp-bus.view');
    Route::get('/erp-bus/messages', [\App\Http\Controllers\Admin\ErpBusController::class, 'messages'])->name('erp-bus.messages')->middleware('permission:erp-bus.view');
    Route::get('/erp-bus/messages/{message}', [\App\Http\Controllers\Admin\ErpBusController::class, 'showMessage'])->name('erp-bus.messages.show')->middleware('permission:erp-bus.view');
    Route::delete('/erp-bus/messages', [\App\Http\Controllers\Admin\ErpBusController::class, 'clearMessages'])->name('erp-bus.clear-messages')->middleware('permission:erp-bus.view');
    Route::delete('/erp-bus/validation-errors', [\App\Http\Controllers\Admin\ErpBusController::class, 'clearValidationErrors'])->name('erp-bus.clear-validation-errors')->middleware('permission:erp-bus.view');
    Route::delete('/erp-bus/processed', [\App\Http\Controllers\Admin\ErpBusController::class, 'clearProcessed'])->name('erp-bus.clear-processed')->middleware('permission:erp-bus.view');

    // Роли
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/roles', [\App\Http\Controllers\Admin\RoleController::class, 'index'])->name('roles.index');
    });
    Route::middleware('permission:roles.create')->group(function () {
        Route::get('/roles/create', [\App\Http\Controllers\Admin\RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [\App\Http\Controllers\Admin\RoleController::class, 'store'])->name('roles.store');
    });
    Route::middleware('permission:roles.edit')->group(function () {
        Route::get('/roles/{role}/edit', [\App\Http\Controllers\Admin\RoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [\App\Http\Controllers\Admin\RoleController::class, 'update'])->name('roles.update');
    });
    Route::delete('/roles/{role}', [\App\Http\Controllers\Admin\RoleController::class, 'destroy'])->name('roles.destroy')->middleware('permission:roles.delete');
});
