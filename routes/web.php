<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\User\PasswordResetController;
use App\Http\Controllers\User\SocialAuthController;
use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ──────────────────────────────────────────────
// Guest routes (only for unauthenticated users)
// ──────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    // Login
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    // Registration
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);

    // Password Reset
    Route::get('/forgot-password', [PasswordResetController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');

    // OAuth
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('auth.social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('auth.social.callback');
});

// ──────────────────────────────────────────────
// Authenticated routes
// ──────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// ──────────────────────────────────────────────
// User-facing routes (Home, Products, Cabinet)
// ──────────────────────────────────────────────
require __DIR__.'/user.php';

// Каталог товаров с поиском (API)
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog');

// AI Generation
Route::post('/ai/generate', [App\Http\Controllers\Admin\AiController::class, 'generate'])->name('admin.ai.generate');

// Публичная ссылка для скачивания выгрузки товаров
Route::get('/export/{hash}', [\App\Http\Controllers\ProductExportDownloadController::class, 'download'])->name('export.download');
