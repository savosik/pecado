<?php

use App\Http\Controllers\Wms\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WMS Routes
|--------------------------------------------------------------------------
|
| Кабинет склада (начальник склада, кладовщики). Защищён middleware 'wms'
| (наличие WMS-права) + явный 'permission:wms-*.action' на каждой группе.
|
| Пока только дашборд: приёмка, отбор и инвентаризация появятся позже.
|
*/

Route::middleware(['web', 'auth', 'wms'])->prefix('wms')->name('wms.')->group(function () {
    Route::middleware('permission:wms-dashboard.view')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    });
});
