<?php

use App\Http\Controllers\Wms\DashboardController;
use App\Http\Controllers\Wms\DefectController;
use App\Http\Controllers\Wms\DefectTypeController;
use App\Http\Controllers\Wms\DeliveryCandidateController;
use App\Http\Controllers\Wms\DeliveryController;
use App\Http\Controllers\Wms\DeliverySettingsController;
use App\Http\Controllers\Wms\GoodsIssueController;
use App\Http\Controllers\Wms\StockBufferController;
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

    // Страховой запас (эпик buf-00): рисковые SKU с занижением показа для
    // клиентов сегмента. Ручные пометки «придержи N шт» ставит склад;
    // расчётный буфер руками не редактируется — пересчитается ночью.
    Route::middleware('permission:wms-stock-buffers.view')->group(function () {
        Route::get('/stock-buffers', [StockBufferController::class, 'index'])->name('stock-buffers.index');
        Route::get('/stock-buffers/search-products', [StockBufferController::class, 'searchProducts'])->name('stock-buffers.search-products');

        Route::middleware('permission:wms-stock-buffers.edit')->group(function () {
            Route::post('/stock-buffers/manual', [StockBufferController::class, 'storeManual'])->name('stock-buffers.manual.store');
            Route::delete('/stock-buffers/{buffer}/manual', [StockBufferController::class, 'clearManual'])->name('stock-buffers.manual.destroy');
        });
    });

    // Расходные ордера из 1С: журнал только на чтение — документ принадлежит 1С,
    // статусами управляет она же. Отсюда и всего два права: view + export.
    Route::middleware('permission:wms-goods-issues.view')->group(function () {
        Route::get('/goods-issues', [GoodsIssueController::class, 'index'])->name('goods-issues.index');

        Route::get('/goods-issues/export', [GoodsIssueController::class, 'export'])
            ->name('goods-issues.export')->middleware('permission:wms-goods-issues.export');

        // Ниже export — иначе «export» попал бы в {goodsIssue} как id.
        Route::get('/goods-issues/{goodsIssue}', [GoodsIssueController::class, 'show'])->name('goods-issues.show');
    });

    // Реализации к доставке — рабочий стол склада перед созданием отправки:
    // фильтры, группировки, скрытие. Отсюда выбранное уходит в мастер.
    Route::middleware('permission:wms-deliveries.view')->group(function () {
        Route::get('/delivery-candidates', [DeliveryCandidateController::class, 'index'])
            ->name('delivery-candidates.index');
        Route::post('/delivery-candidates/hide', [DeliveryCandidateController::class, 'toggleHidden'])
            ->name('delivery-candidates.hide')->middleware('permission:wms-deliveries.create');
        // Отметка «уже отправлено» — тоже создание отправки, только без ApiShip.
        Route::post('/delivery-candidates/mark-shipped', [DeliveryCandidateController::class, 'markShipped'])
            ->name('delivery-candidates.mark-shipped')->middleware('permission:wms-deliveries.create');
    });

    // Настройки интеграции с ApiShip: токен и адрес отправителя ведёт начальник
    // склада — договор с перевозчиком перевыпускают без участия разработчика.
    Route::middleware('permission:wms-delivery-settings.view')->group(function () {
        Route::get('/delivery-settings', [DeliverySettingsController::class, 'edit'])
            ->name('delivery-settings.edit');
        Route::put('/delivery-settings', [DeliverySettingsController::class, 'update'])
            ->name('delivery-settings.update')->middleware('permission:wms-delivery-settings.edit');
        Route::post('/delivery-settings/test', [DeliverySettingsController::class, 'test'])
            ->name('delivery-settings.test')->middleware('permission:wms-delivery-settings.edit');
    });

    // Отправки транспортными компаниями (ApiShip). В отличие от расходных ордеров
    // это документ сайта: 1С про него ничего не знает и по шине он не ходит.
    Route::middleware('permission:wms-deliveries.view')->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');

        // Статичные сегменты — выше {delivery}, иначе «create» уедет в параметр.
        Route::get('/deliveries/create', [DeliveryController::class, 'create'])
            ->name('deliveries.create')->middleware('permission:wms-deliveries.create');
        Route::get('/deliveries/search-shipments', [DeliveryController::class, 'searchShipments'])
            ->name('deliveries.search-shipments')->middleware('permission:wms-deliveries.create');
        Route::get('/deliveries/recipient-options', [DeliveryController::class, 'recipientOptions'])
            ->name('deliveries.recipient-options')->middleware('permission:wms-deliveries.create');
        Route::post('/deliveries/resolve-address', [DeliveryController::class, 'resolveAddress'])
            ->name('deliveries.resolve-address')->middleware('permission:wms-deliveries.create');
        Route::post('/deliveries', [DeliveryController::class, 'store'])
            ->name('deliveries.store')->middleware('permission:wms-deliveries.create');

        Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::get('/deliveries/{delivery}/points', [DeliveryController::class, 'points'])->name('deliveries.points');
        Route::get('/deliveries/{delivery}/label', [DeliveryController::class, 'label'])->name('deliveries.label');
        Route::get('/deliveries/{delivery}/waybill', [DeliveryController::class, 'waybill'])->name('deliveries.waybill');

        Route::middleware('permission:wms-deliveries.edit')->group(function () {
            Route::get('/deliveries/{delivery}/edit', [DeliveryController::class, 'edit'])->name('deliveries.edit');
            Route::put('/deliveries/{delivery}', [DeliveryController::class, 'update'])->name('deliveries.update');
            Route::post('/deliveries/{delivery}/calculate', [DeliveryController::class, 'calculate'])->name('deliveries.calculate');
            Route::delete('/deliveries/{delivery}', [DeliveryController::class, 'destroy'])->name('deliveries.destroy');
        });

        Route::middleware('permission:wms-deliveries.submit')->group(function () {
            Route::post('/deliveries/{delivery}/submit', [DeliveryController::class, 'submit'])->name('deliveries.submit');
            Route::post('/deliveries/{delivery}/courier', [DeliveryController::class, 'courier'])->name('deliveries.courier');
        });

        // Отмена уже принятой заявки может стоить денег — только начальнику склада.
        Route::post('/deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])
            ->name('deliveries.cancel')->middleware('permission:wms-deliveries.cancel');
    });

    // Справочник типовых дефектов: ведёт начальник склада (в /admin роль не пускает).
    // Кладовщик справочник только использует чипами — прав на правку у него нет.
    Route::middleware('permission:wms-defect-types.view')->group(function () {
        Route::get('/defect-types', [DefectTypeController::class, 'index'])->name('defect-types.index');

        Route::post('/defect-types', [DefectTypeController::class, 'store'])
            ->name('defect-types.store')->middleware('permission:wms-defect-types.create');
        Route::put('/defect-types/{defectType}', [DefectTypeController::class, 'update'])
            ->name('defect-types.update')->middleware('permission:wms-defect-types.edit');
        Route::delete('/defect-types/{defectType}', [DefectTypeController::class, 'destroy'])
            ->name('defect-types.destroy')->middleware('permission:wms-defect-types.delete');
    });
});
