<?php

use App\Http\Controllers\User\CatalogApiController;
use App\Http\Controllers\User\CatalogController as UserCatalogController;
use App\Http\Controllers\User\PageController as UserPageController;
use App\Http\Controllers\User\ProductController;
use App\Http\Controllers\User\SearchController;
use Illuminate\Support\Facades\Route;

// Поисковые подсказки (Autocomplete)
Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('api.search.suggestions');

// Каталог-панель (категории + бренды)
Route::get('/catalog/categories', [UserCatalogController::class, 'categories'])->name('api.catalog.categories');
Route::get('/catalog/brands', [UserCatalogController::class, 'brands'])->name('api.catalog.brands');
Route::get('/catalog/selections', [UserCatalogController::class, 'selections'])->name('api.catalog.selections');

// Каталог товаров: список, фасеты, ценовые интервалы
Route::get('/catalog/products', [CatalogApiController::class, 'products'])->name('api.catalog.products');
Route::get('/catalog/products/facets', [CatalogApiController::class, 'facets'])->name('api.catalog.products.facets');
Route::get('/catalog/products/price-intervals', [CatalogApiController::class, 'priceIntervals'])->name('api.catalog.products.price-intervals');

// Товары по массиву ID — для блока productCarousel в контенте
Route::get('/products/by-ids', [\App\Http\Controllers\User\ProductByIdsController::class, '__invoke'])->name('api.products.by-ids');

// QuickView — JSON-карточка товара
Route::get('/products/{product:slug}', [ProductController::class, 'showJson'])->name('api.products.show');

// CMS-страницы по slug — для встроенного показа в модалках (политика, согласие и т.п.)
Route::get('/pages/{slug}', [UserPageController::class, 'apiShow'])->name('api.pages.show');

// Лениво генерируемое rich-описание товара (Editor.js JSON через OpenRouter)
Route::get('/products/{product:slug}/rich-content', [\App\Http\Controllers\Api\ProductRichContentController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('api.products.rich-content');

// ──────────────────────────────────────────────────────────────
// Content API — для ИИ-агента контент-менеджера
// ──────────────────────────────────────────────────────────────
Route::prefix('content')
    ->middleware(['auth:sanctum', 'throttle:content-api'])
    ->name('api.content.')
    ->group(function () {
        // Информация об агенте
        Route::get('me', [\App\Http\Controllers\Api\Content\MeController::class, 'index'])->name('me');

        // Загрузка медиафайлов (картинки, видео)
        Route::post('media', [\App\Http\Controllers\Api\Content\MediaUploadController::class, 'store'])->name('media.store');

        // ── Контент — полный CRUD ──────────────────────────────────
        Route::apiResource('news', \App\Http\Controllers\Api\Content\NewsController::class)->parameters(['news' => 'news']);
        Route::apiResource('articles', \App\Http\Controllers\Api\Content\ArticleController::class);
        Route::apiResource('faqs', \App\Http\Controllers\Api\Content\FaqController::class);
        Route::apiResource('brand-stories', \App\Http\Controllers\Api\Content\BrandStoryController::class)->parameters(['brand-stories' => 'brandStory']);
        Route::apiResource('banners', \App\Http\Controllers\Api\Content\BannerController::class);
        Route::apiResource('pages', \App\Http\Controllers\Api\Content\PageController::class);

        // Промоакции + привязка товаров
        Route::apiResource('promotions', \App\Http\Controllers\Api\Content\PromotionController::class);
        Route::post('promotions/{promotion}/products', [\App\Http\Controllers\Api\Content\PromotionController::class, 'syncProducts'])->name('promotions.sync-products');

        // Подборки товаров + привязка товаров с featured
        Route::apiResource('product-selections', \App\Http\Controllers\Api\Content\ProductSelectionController::class)->parameters(['product-selections' => 'productSelection']);
        Route::post('product-selections/{productSelection}/products', [\App\Http\Controllers\Api\Content\ProductSelectionController::class, 'syncProductsEndpoint'])->name('product-selections.sync-products');

        // Сториз + вложенные слайды
        Route::apiResource('stories', \App\Http\Controllers\Api\Content\StoryController::class);
        Route::get('stories/{story}/slides', [\App\Http\Controllers\Api\Content\StorySlideController::class, 'index'])->name('stories.slides.index');
        Route::post('stories/{story}/slides', [\App\Http\Controllers\Api\Content\StorySlideController::class, 'store'])->name('stories.slides.store');
        Route::put('stories/{story}/slides/{slide}', [\App\Http\Controllers\Api\Content\StorySlideController::class, 'update'])->name('stories.slides.update');
        Route::delete('stories/{story}/slides/{slide}', [\App\Http\Controllers\Api\Content\StorySlideController::class, 'destroy'])->name('stories.slides.destroy');
        Route::post('stories/{story}/slides/reorder', [\App\Http\Controllers\Api\Content\StorySlideController::class, 'reorder'])->name('stories.slides.reorder');

        // Теги (view + create)
        Route::get('tags', [\App\Http\Controllers\Api\Content\TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [\App\Http\Controllers\Api\Content\TagController::class, 'store'])->name('tags.store');

        // ── Каталог — READ-ONLY ────────────────────────────────────
        Route::get('products', [\App\Http\Controllers\Api\Content\ProductController::class, 'index'])->name('products.index');
        Route::get('products/search', [\App\Http\Controllers\Api\Content\ProductController::class, 'search'])->name('products.search');
        Route::get('products/facets', [\App\Http\Controllers\Api\Content\ProductController::class, 'facets'])->name('products.facets');
        Route::get('products/price-intervals', [\App\Http\Controllers\Api\Content\ProductController::class, 'priceIntervals'])->name('products.price-intervals');
        Route::get('products/{product}', [\App\Http\Controllers\Api\Content\ProductController::class, 'show'])->name('products.show');

        // ── Каталог — изображения товаров (запись) ─────────────────
        Route::get('products/{product}/media', [\App\Http\Controllers\Api\Content\ProductMediaController::class, 'index'])->name('products.media.index');
        Route::post('products/{product}/media', [\App\Http\Controllers\Api\Content\ProductMediaController::class, 'store'])->name('products.media.store');
        Route::delete('products/{product}/media/{media}', [\App\Http\Controllers\Api\Content\ProductMediaController::class, 'destroy'])->name('products.media.destroy');

        Route::get('brands', [\App\Http\Controllers\Api\Content\BrandController::class, 'index'])->name('brands.index');
        Route::get('brands/{brand}', [\App\Http\Controllers\Api\Content\BrandController::class, 'show'])->name('brands.show');

        Route::get('categories', [\App\Http\Controllers\Api\Content\CategoryController::class, 'index'])->name('categories.index');
        Route::get('categories/{category}', [\App\Http\Controllers\Api\Content\CategoryController::class, 'show'])->name('categories.show');

        Route::get('regions', [\App\Http\Controllers\Api\Content\RegionController::class, 'index'])->name('regions.index');
    });

// ──────────────────────────────────────────────────────────────
// CRM API — для ИИ-агентов менеджеров отдела продаж
//
// Маршруты не перечислены руками, а собраны обходом OperationRegistry: реестр —
// единственный источник, из которого растут и адреса, и документация, и каталог
// инструментов MCP. Добавление операции здесь не требует правок.
//
// Токен превращается в сотрудника (AuthenticateCrmAgent), поэтому право на
// каждой операции проверяется его же правами — см. OperationRunner.
// ──────────────────────────────────────────────────────────────
Route::prefix('crm')
    ->middleware([\App\Http\Middleware\AuthenticateCrmAgent::class, 'throttle:crm-api'])
    ->name(\App\Http\Controllers\Api\Crm\CrmApiController::ROUTE_PREFIX)
    ->group(function () {
        Route::get('me', [\App\Http\Controllers\Api\Crm\CrmApiController::class, 'me'])->name('me');

        foreach (app(\App\Services\Crm\Api\OperationRegistry::class)->callable() as $operation) {
            $route = Route::match([$operation->method], $operation->uri, [
                \App\Http\Controllers\Api\Crm\CrmApiController::class, 'run',
            ])->name($operation->id);

            // Идентификаторы всегда числовые: иначе `clients/me` ушло бы
            // в карточку клиента и вернуло 404 вместо осмысленного ответа.
            foreach ($operation->pathParams() as $param) {
                $route->whereNumber($param);
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// Kanban API — для LLM-агентов (публичный, без авторизации)
// Активируется переменной KANBAN_API_ENABLED=true
// ──────────────────────────────────────────────────────────────
Route::prefix('kanban')
    ->middleware([\App\Http\Middleware\KanbanApiEnabled::class, 'throttle:120,1'])
    ->name('api.kanban.')
    ->group(function () {
        Route::get('tasks', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'index'])->name('tasks.index');
        Route::post('tasks', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'store'])->name('tasks.store');
        Route::get('tasks/{id}', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'show'])->name('tasks.show');
        Route::patch('tasks/{id}', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{id}', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'destroy'])->name('tasks.destroy');

        Route::post('tasks/{id}/comments', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'addComment'])->name('tasks.comments.store');
        Route::delete('comments/{commentId}', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'deleteComment'])->name('comments.destroy');

        Route::post('tasks/{id}/attachments', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'addAttachment'])->name('tasks.attachments.store');
        Route::delete('attachments/{attachmentId}', [\App\Http\Controllers\Api\Kanban\KanbanApiController::class, 'deleteAttachment'])->name('attachments.destroy');
    });

// ──────────────────────────────────────────────────────────────
// Client API — публичные эндпоинты по хешу (без авторизации)
// ──────────────────────────────────────────────────────────────
Route::prefix('client-api/{token}')
    ->middleware('throttle:60,1')
    ->name('client-api.')
    ->group(function () {
        Route::get('/prices', [\App\Http\Controllers\Api\ClientApiController::class, 'prices'])->name('prices');
        Route::get('/stocks', [\App\Http\Controllers\Api\ClientApiController::class, 'stocks'])->name('stocks');
        Route::get('/order-changes', [\App\Http\Controllers\Api\ClientApiController::class, 'orderChanges'])->name('order-changes');
        Route::post('/orders', [\App\Http\Controllers\Api\ClientApiController::class, 'orders'])->name('orders');
    });
