<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Authentication routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'laravelVersion' => app()->version(),
        'phpVersion' => PHP_VERSION,
    ]);
});

// Каталог товаров с поиском
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog');

// AI Generation
Route::post('/ai/generate', [App\Http\Controllers\Admin\AiController::class, 'generate'])->name('admin.ai.generate');

// Публичная ссылка для скачивания выгрузки товаров
Route::get('/export/{hash}', [\App\Http\Controllers\ProductExportDownloadController::class, 'download'])->name('export.download');
