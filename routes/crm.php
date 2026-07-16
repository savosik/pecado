<?php

use App\Http\Controllers\Crm\AnalyticsController;
use App\Http\Controllers\Crm\ClientController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM Routes
|--------------------------------------------------------------------------
|
| Домен отдела продаж. Защищён middleware 'crm' (наличие CRM-права)
| + явный 'permission:crm-*.action' на каждой группе.
|
| Видимость клиентов ограничивает scope User::visibleInCrm() — см. ClientController.
|
*/

Route::middleware(['web', 'auth', 'crm'])->prefix('crm')->name('crm.')->group(function () {
    Route::middleware('permission:crm-dashboard.view')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    });

    Route::middleware('permission:crm-clients.view')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        // Без implicit binding: клиента резолвим через scope, иначе чужой
        // вернул бы 403 вместо 404 и подтвердил факт существования.
        Route::get('/clients/{client}', [ClientController::class, 'show'])
            ->name('clients.show')
            ->whereNumber('client');
    });

    Route::middleware('permission:crm-team.view')->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
    });

    Route::middleware('permission:crm-analytics.view')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analytics/data', [AnalyticsController::class, 'data'])->name('analytics.data');
        Route::get('/analytics/abc-xyz', [AnalyticsController::class, 'abcXyz'])->name('analytics.abc-xyz');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    });
});
