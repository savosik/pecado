<?php

use App\Http\Controllers\Wms\DashboardController;
use App\Http\Controllers\Wms\DefectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WMS Routes
|--------------------------------------------------------------------------
|
| Кабинет склада (начальник склада, кладовщики). Защищён middleware 'wms'
| (наличие WMS-права) + явный 'permission:wms-*.action' на каждой группе.
|
| Приёмка, отбор и инвентаризация появятся позже.
|
*/

Route::middleware(['web', 'auth', 'wms'])->prefix('wms')->name('wms.')->group(function () {
    Route::middleware('permission:wms-dashboard.view')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    });

    // Некондиция: кладовщик ведёт партии брака с фото. Цену и публикацию
    // на сайте задаёт закупщик в /admin (право defects.*).
    Route::middleware('permission:wms-defects.view')->group(function () {
        Route::get('/defects', [DefectController::class, 'index'])->name('defects.index');
        Route::get('/defects/shipping', [DefectController::class, 'shipping'])->name('defects.shipping');
        Route::get('/defects/uncovered', [DefectController::class, 'uncovered'])->name('defects.uncovered');
        Route::get('/defects/codes', [DefectController::class, 'codes'])->name('defects.codes');
        Route::get('/defects/codes/export', [DefectController::class, 'codesExport'])->name('defects.codes-export');
        Route::get('/defects/search-products', [DefectController::class, 'searchProducts'])->name('defects.search-products');
        Route::get('/defects/resolve-barcode', [DefectController::class, 'resolveBarcode'])->name('defects.resolve-barcode');

        Route::middleware('permission:wms-defects.create')->group(function () {
            Route::get('/defects/create', [DefectController::class, 'create'])->name('defects.create');
            Route::get('/defects/quick', [DefectController::class, 'quick'])->name('defects.quick');
            Route::post('/defects/quick', [DefectController::class, 'quickStore'])->name('defects.quick-store');
            Route::post('/defects', [DefectController::class, 'store'])->name('defects.store');
        });

        Route::middleware('permission:wms-defects.edit')->group(function () {
            Route::get('/defects/{defect}/edit', [DefectController::class, 'edit'])->name('defects.edit');
            Route::put('/defects/{defect}', [DefectController::class, 'update'])->name('defects.update');
            Route::post('/defects/{defect}/reopen', [DefectController::class, 'reopen'])->name('defects.reopen');
        });

        Route::middleware('permission:wms-defects.delete')->group(function () {
            Route::post('/defects/{defect}/write-off', [DefectController::class, 'writeOff'])->name('defects.write-off');
            Route::delete('/defects/{defect}', [DefectController::class, 'destroy'])->name('defects.destroy');
        });
    });
});
