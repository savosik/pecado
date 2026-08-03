<?php

use App\Http\Controllers\Crm\AnalyticsController;
use App\Http\Controllers\Crm\AttachmentController;
use App\Http\Controllers\Crm\ClientController;
use App\Http\Controllers\Crm\ClientProfileController;
use App\Http\Controllers\Crm\CommentController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\TaskController;
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

    // Профиль клиента — то, что знает менеджер, но не знает 1С.
    Route::middleware('permission:crm-profile.view')->group(function () {
        Route::get('/interests', [ClientProfileController::class, 'interests'])->name('interests.search');
    });

    Route::middleware('permission:crm-profile.edit')->group(function () {
        Route::put('/clients/{client}/profile', [ClientProfileController::class, 'update'])
            ->name('clients.profile.update')
            ->whereNumber('client');
        // Жизненный статус живёт в том же профиле, поэтому отдельного ресурса прав
        // не заводим: их в матрице ролей и так много.
        Route::put('/clients/{client}/lifecycle', [ClientProfileController::class, 'lifecycle'])
            ->name('clients.lifecycle.update')
            ->whereNumber('client');
    });

    // Комментарии. Отдают JSON: тот же компонент ленты встраивается и в карточку
    // клиента, и в админские карточки заказа и реализации.
    Route::middleware('permission:crm-comments.view')->group(function () {
        Route::get('/clients/{client}/timeline', [CommentController::class, 'timeline'])
            ->name('clients.timeline')
            ->whereNumber('client');
        Route::get('/comments', [CommentController::class, 'index'])->name('comments.index');
        Route::post('/comments', [CommentController::class, 'store'])->name('comments.store');
        Route::patch('/comments/{comment}', [CommentController::class, 'update'])
            ->name('comments.update')
            ->whereNumber('comment');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])
            ->name('comments.destroy')
            ->whereNumber('comment');
    });

    // Задачи. Раздел — Inertia-страница, остальное JSON: диалог и врезка встраиваются
    // в карточки клиента, заказа и реализации, где редирект увёл бы со страницы.
    Route::middleware('permission:crm-tasks.view')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        // До /tasks/{task}: иначе «list» и «options» ушли бы в биндинг модели.
        Route::get('/tasks/list', [TaskController::class, 'list'])->name('tasks.list');
        Route::get('/tasks/options', [TaskController::class, 'options'])->name('tasks.options');
        Route::get('/tasks/entities', [TaskController::class, 'entities'])->name('tasks.entities');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])
            ->name('tasks.show')
            ->whereNumber('task');
    });

    Route::middleware('permission:crm-tasks.create')->group(function () {
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    });

    Route::middleware('permission:crm-tasks.edit')->group(function () {
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])
            ->name('tasks.update')
            ->whereNumber('task');
        // Закрытие — своим эндпоинтом: отметка, отчёт и следующий шаг
        // должны лечь одной транзакцией.
        Route::post('/tasks/{task}/close', [TaskController::class, 'close'])
            ->name('tasks.close')
            ->whereNumber('task');
    });

    Route::middleware('permission:crm-tasks.delete')->group(function () {
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])
            ->name('tasks.destroy')
            ->whereNumber('task');
    });

    // Вложения. Живут в существующей MediaLibrary (коллекция crm-attachments),
    // своей таблицы у них нет.
    Route::middleware('permission:crm-attachments.view')->group(function () {
        Route::get('/attachments', [AttachmentController::class, 'index'])->name('attachments.index');
        // Файл отдаём через приложение, а не прямой ссылкой на диск: публичный URL
        // раздал бы документы клиента в обход скоупа.
        Route::get('/attachments/{media}/download', [AttachmentController::class, 'download'])
            ->name('attachments.download')
            ->whereNumber('media');
        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::delete('/attachments/{media}', [AttachmentController::class, 'destroy'])
            ->name('attachments.destroy')
            ->whereNumber('media');
    });

    Route::middleware('permission:crm-team.view')->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
    });

    Route::middleware('permission:crm-analytics.view')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analytics/data', [AnalyticsController::class, 'data'])->name('analytics.data');
        Route::get('/analytics/abc-xyz', [AnalyticsController::class, 'abcXyz'])->name('analytics.abc-xyz');
        Route::get('/analytics/gap', [AnalyticsController::class, 'gap'])->name('analytics.gap');
        Route::get('/analytics/gap/export', [AnalyticsController::class, 'gapExport'])->name('analytics.gap.export');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
        Route::post('/analytics/presets', [AnalyticsController::class, 'storePreset'])->name('analytics.presets.store');
        Route::delete('/analytics/presets/{preset}', [AnalyticsController::class, 'destroyPreset'])
            ->whereNumber('preset')->name('analytics.presets.destroy');
        Route::get('/products/search', [AnalyticsController::class, 'searchProducts'])->name('products.search');
    });
});
