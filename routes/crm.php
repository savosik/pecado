<?php

use App\Http\Controllers\Crm\AgentTokenController;
use App\Http\Controllers\Crm\AnalyticsController;
use App\Http\Controllers\Crm\AttachmentController;
use App\Http\Controllers\Crm\BedsController;
use App\Http\Controllers\Crm\CallController;
use App\Http\Controllers\Crm\ClientController;
use App\Http\Controllers\Crm\ClientProfileController;
use App\Http\Controllers\Crm\CommentController;
use App\Http\Controllers\Crm\ContractorController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\DocumentController;
use App\Http\Controllers\Crm\EmailController;
use App\Http\Controllers\Crm\FinanceController;
use App\Http\Controllers\Crm\ImpersonationController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\LeadStageController;
use App\Http\Controllers\Crm\OpportunityController;
use App\Http\Controllers\Crm\PlanController;
use App\Http\Controllers\Crm\PresenceController;
use App\Http\Controllers\Crm\ShortageController;
use App\Http\Controllers\Crm\TaskController;
use App\Http\Controllers\Crm\TaskRecurrenceController;
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
| Видимость партнёров ограничивает scope User::visibleInCrm() — см. ClientController,
| видимость их юрлиц — Company::visibleInCrm() (см. ContractorController).
|
| Про расхождение имён: в интерфейсе и в адресах покупатель называется партнёром —
| так его зовут в 1С, и так его отличают от контрагента (юрлица). В коде, правах
| (`crm-clients.*`), именах маршрутов (`crm.clients.*`) и колонках БД осталось
| историческое «client»: права уже розданы ролям на проде, а `client_user_id` лежит
| в миллионах строк — переименование ради слова не стоит миграции такого размера.
|
*/

Route::middleware(['web', 'auth', 'crm'])->prefix('crm')->name('crm.')->group(function () {
    Route::middleware('permission:crm-dashboard.view')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Полоска «кто сейчас на сайте». Опрашивается раз в ~45 секунд с каждой
        // открытой вкладки CRM, поэтому запрос обязан оставаться дешёвым:
        // один индексированный WHERE по users.last_seen_at и срез в семь строк.
        Route::get('/presence', PresenceController::class)->name('presence');
    });

    // Старые адреса раздела партнёров. Менеджеры держат карточки в закладках
    // и присылают ссылки друг другу — без редиректа переезд означал бы 404
    // в чужой переписке. Только GET: POST/PUT/DELETE ходят из интерфейса,
    // который уже знает новые адреса.
    // Имена обязательны: без них оба редиректа получили бы имя группы («crm.»)
    // и второй затёр бы первый в таблице маршрутов.
    Route::permanentRedirect('/clients', '/crm/partners')->name('clients.index.legacy');
    Route::permanentRedirect('/clients/{client}', '/crm/partners/{client}')
        ->name('clients.show.legacy')
        ->whereNumber('client');

    Route::middleware('permission:crm-clients.view')->group(function () {
        Route::get('/partners', [ClientController::class, 'index'])->name('clients.index');
        // Личные отборы списка — до /partners/{client}, иначе «presets» ушло бы
        // в маршрут карточки. Отдельного права нет: отбор личный и живёт под тем
        // же crm-clients.view, что и сам список.
        Route::post('/partners/presets', [ClientController::class, 'storePreset'])
            ->name('clients.presets.store');
        Route::delete('/partners/presets/{preset}', [ClientController::class, 'destroyPreset'])
            ->name('clients.presets.destroy')
            ->whereNumber('preset');
        // Без implicit binding: партнёра резолвим через scope, иначе чужой
        // вернул бы 403 вместо 404 и подтвердил факт существования.
        Route::get('/partners/{client}', [ClientController::class, 'show'])
            ->name('clients.show')
            ->whereNumber('client');

        // Закупки партнёра для карточки — отдельным запросом, а не в пропсах
        // страницы: разрез по брендам и категориям нужен не при каждом открытии
        // карточки, а карточку открывают десятки раз за день. Право то же, что
        // и у журналов документов: суммы по своему партнёру менеджер и так видит.
        Route::get('/partners/{client}/insights', [ClientController::class, 'insights'])
            ->name('clients.insights')
            ->whereNumber('client');

        // Документы внутри CRM. Отдельного права нет: «вижу партнёра, но не вижу
        // его заказы» — состояние, которого быть не должно. Списки ограничены тем же
        // скоупом партнёров, карточка резолвится через CrmEntityResolver (чужая — 404).
        //
        // Списки объявлены до /{order} и /{shipment} — иначе «orders» ушло бы
        // в биндинг модели.
        Route::get('/orders', [DocumentController::class, 'orders'])->name('orders.index');
        Route::get('/shipments', [DocumentController::class, 'shipments'])->name('shipments.index');
        // XLSX по текущему отбору — тот же скоуп и те же фильтры, что у списка.
        Route::get('/orders/export', [DocumentController::class, 'ordersExport'])->name('orders.export');
        Route::get('/shipments/export', [DocumentController::class, 'shipmentsExport'])->name('shipments.export');
        // Подсказки товаров для фильтра журналов. Свой маршрут, а не
        // crm.products.search: тот закрыт правом crm-analytics.view, которого
        // у рядового менеджера может не быть.
        Route::get('/documents/products/search', [DocumentController::class, 'searchProducts'])
            ->name('documents.products.search');
        // Печатные формы из 1С (v16.1.0). Отдельного права тоже нет — по той же
        // причине, что и у журналов выше. Список и выгрузка объявлены до /{id},
        // иначе «export» ушло бы в биндинг модели.
        Route::get('/printed-documents', [DocumentController::class, 'printedDocuments'])
            ->name('printed-documents.index');
        Route::get('/printed-documents/export', [DocumentController::class, 'printedDocumentsExport'])
            ->name('printed-documents.export');
        Route::get('/printed-documents/{printedDocument}/download', [DocumentController::class, 'printedDocumentDownload'])
            ->name('printed-documents.download')
            ->whereNumber('printedDocument');
        Route::get('/orders/{order}', [DocumentController::class, 'order'])
            ->name('orders.show')
            ->whereNumber('order');
        Route::get('/payments', [DocumentController::class, 'payments'])->name('payments.index');
        Route::get('/payments/export', [DocumentController::class, 'paymentsExport'])->name('payments.export');
        // Календарь поступления денег (v15.12.0): план по графику 1С + факт
        // по проведённым платежам. Объявлен до /payments/{payment}.
        Route::get('/payments/calendar', [DocumentController::class, 'paymentsCalendar'])->name('payments.calendar');
        Route::get('/payments/{payment}', [DocumentController::class, 'payment'])
            ->name('payments.show')
            ->whereNumber('payment');
        Route::get('/shipments/{shipment}', [DocumentController::class, 'shipment'])
            ->name('shipments.show')
            ->whereNumber('shipment');
    });

    // Лиды: потенциальные клиенты до появления партнёра в 1С. Доска отдаётся
    // Inertia, перемещение карточки — JSON: перетаскивание не должно
    // перезагружать страницу.
    Route::middleware('permission:crm-leads.view')->group(function () {
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    });

    Route::middleware('permission:crm-leads.create')->group(function () {
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    });

    Route::middleware('permission:crm-leads.edit')->group(function () {
        Route::patch('/leads/{lead}', [LeadController::class, 'update'])
            ->name('leads.update')->whereNumber('lead');
        Route::post('/leads/{lead}/move', [LeadController::class, 'move'])
            ->name('leads.move')->whereNumber('lead');
        // Квалификация в клиента — привязка к партнёру, которого создала 1С.
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])
            ->name('leads.convert')->whereNumber('lead');
        // Разбор пачкой: назначить менеджера, перенести по воронке, удалить.
        // Право на удаление проверяется внутри — оно строже права на правку.
        Route::post('/leads/bulk', [LeadController::class, 'bulk'])->name('leads.bulk');
    });

    Route::middleware('permission:crm-leads.delete')->group(function () {
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])
            ->name('leads.destroy')->whereNumber('lead');
    });

    // Состав воронки — дело руководителя отдела.
    Route::middleware('permission:crm-lead-stages.edit')->group(function () {
        Route::post('/lead-stages', [LeadStageController::class, 'store'])->name('lead-stages.store');
        Route::patch('/lead-stages/{stage}', [LeadStageController::class, 'update'])
            ->name('lead-stages.update')->whereNumber('stage');
    });

    Route::middleware('permission:crm-lead-stages.delete')->group(function () {
        Route::delete('/lead-stages/{stage}', [LeadStageController::class, 'destroy'])
            ->name('lead-stages.destroy')->whereNumber('stage');
    });

    // Контрагенты — юрлица партнёров. Только чтение: реквизиты ведёт 1С, а работа
    // с контрагентом идёт задачами, комментариями и файлами через общие эндпоинты
    // (тип `contractor` в CrmEntityMap). Планов по контрагентам нет принципиально —
    // они считаются по партнёру.
    Route::middleware('permission:crm-contractors.view')->group(function () {
        Route::get('/contractors', [ContractorController::class, 'index'])->name('contractors.index');
        // Без implicit binding: контрагента резолвим через scope, иначе чужой
        // вернул бы 403 вместо 404 и подтвердил факт существования.
        Route::get('/contractors/{contractor}', [ContractorController::class, 'show'])
            ->name('contractors.show')
            ->whereNumber('contractor');
    });

    // Просмотр сайта от имени партнёра. Право отдельное: режим переключает
    // сессию, а не показывает данные, и выдаётся не всем, кто видит карточку.
    // Выход из режима — POST /impersonation/stop в routes/web.php: в тот момент
    // сессия принадлежит партнёру, и в CRM-группу он уже не попадает.
    Route::middleware('permission:crm-impersonate.use')->group(function () {
        Route::post('/partners/{client}/impersonate', [ImpersonationController::class, 'start'])
            ->name('impersonation.start')
            ->whereNumber('client');
    });

    // Профиль партнёра — то, что знает менеджер, но не знает 1С.
    Route::middleware('permission:crm-profile.view')->group(function () {
        Route::get('/interests', [ClientProfileController::class, 'interests'])->name('interests.search');
    });

    Route::middleware('permission:crm-profile.edit')->group(function () {
        Route::put('/partners/{client}/profile', [ClientProfileController::class, 'update'])
            ->name('clients.profile.update')
            ->whereNumber('client');
        // Жизненный статус живёт в том же профиле, поэтому отдельного ресурса прав
        // не заводим: их в матрице ролей и так много.
        Route::put('/partners/{client}/lifecycle', [ClientProfileController::class, 'lifecycle'])
            ->name('clients.lifecycle.update')
            ->whereNumber('client');
    });

    // Тип аккаунта — состав базы партнёров отдела, а не работа с партнёром,
    // поэтому право отдельное и менеджеру не выдано.
    Route::middleware('permission:crm-clients-all.edit')->group(function () {
        Route::put('/partners/{client}/kind', [ClientProfileController::class, 'kind'])
            ->name('clients.kind.update')
            ->whereNumber('client');
    });

    // Комментарии. Отдают JSON: тот же компонент ленты встраивается и в карточку
    // партнёра, и в админские карточки заказа и реализации.
    Route::middleware('permission:crm-comments.view')->group(function () {
        Route::get('/partners/{client}/timeline', [CommentController::class, 'timeline'])
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
    // в карточки партнёра, заказа и реализации, где редирект увёл бы со страницы.
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
        // Правила автоповтора. Отдельный ресурс, а не режим задачи: правило —
        // это станок, а не поручение, и живёт оно своей жизнью.
        Route::post('/task-recurrences', [TaskRecurrenceController::class, 'store'])
            ->name('task-recurrences.store');
    });

    Route::middleware('permission:crm-tasks.view')->group(function () {
        Route::get('/task-recurrences', [TaskRecurrenceController::class, 'index'])
            ->name('task-recurrences.index');
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

    Route::middleware('permission:crm-tasks.edit')->group(function () {
        Route::patch('/task-recurrences/{recurrence}', [TaskRecurrenceController::class, 'update'])
            ->name('task-recurrences.update')
            ->whereNumber('recurrence');
        // Отмена цепочки — is_active=false: уже созданные задачи остаются
        // в истории вместе с отчётами о закрытии.
        Route::delete('/task-recurrences/{recurrence}', [TaskRecurrenceController::class, 'destroy'])
            ->name('task-recurrences.destroy')
            ->whereNumber('recurrence');
    });

    Route::middleware('permission:crm-tasks.delete')->group(function () {
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])
            ->name('tasks.destroy')
            ->whereNumber('task');
    });

    // Звонки. Телефония не подключена — записываются руками из диалога и из ленты;
    // когда появится АТС, тот же журнал начнёт наполняться вебхуком.
    Route::middleware('permission:crm-calls.view')->group(function () {
        // До /calls/{call}: иначе «options» ушло бы в биндинг модели.
        Route::get('/calls/options', [CallController::class, 'options'])->name('calls.options');
        Route::get('/calls', [CallController::class, 'index'])->name('calls.index');
    });

    Route::middleware('permission:crm-calls.create')->group(function () {
        Route::post('/calls', [CallController::class, 'store'])->name('calls.store');
    });

    Route::middleware('permission:crm-calls.edit')->group(function () {
        Route::patch('/calls/{call}', [CallController::class, 'update'])
            ->name('calls.update')
            ->whereNumber('call');
    });

    Route::middleware('permission:crm-calls.delete')->group(function () {
        Route::delete('/calls/{call}', [CallController::class, 'destroy'])
            ->name('calls.destroy')
            ->whereNumber('call');
    });

    // Письма. Отправка гейтится фича-флагом MAIL_FEATURE_CRM_OUTBOUND,
    // черновики создаются и при выключенном.
    Route::middleware('permission:crm-emails.view')->group(function () {
        Route::get('/emails', [EmailController::class, 'index'])->name('emails.index');
        // До /emails/{email}: иначе «list» и «options» ушли бы в биндинг модели.
        Route::get('/emails/list', [EmailController::class, 'list'])->name('emails.list');
        Route::get('/emails/options', [EmailController::class, 'options'])->name('emails.options');
        Route::get('/emails/{email}', [EmailController::class, 'show'])
            ->name('emails.show')
            ->whereNumber('email');
    });

    Route::middleware('permission:crm-emails.create')->group(function () {
        Route::post('/emails', [EmailController::class, 'store'])->name('emails.store');
        Route::post('/emails/{email}/send', [EmailController::class, 'send'])
            ->name('emails.send')
            ->whereNumber('email');
        Route::get('/email-templates/{template}', [EmailController::class, 'template'])
            ->name('emails.template')
            ->whereNumber('template');
    });

    Route::middleware('permission:crm-emails.edit')->group(function () {
        Route::patch('/emails/{email}', [EmailController::class, 'update'])
            ->name('emails.update')
            ->whereNumber('email');
    });

    Route::middleware('permission:crm-emails.delete')->group(function () {
        Route::delete('/emails/{email}', [EmailController::class, 'destroy'])
            ->name('emails.destroy')
            ->whereNumber('email');
    });

    // Вложения. Живут в существующей MediaLibrary (коллекция crm-attachments),
    // своей таблицы у них нет.
    Route::middleware('permission:crm-attachments.view')->group(function () {
        Route::get('/attachments', [AttachmentController::class, 'index'])->name('attachments.index');
        // Файл отдаём через приложение, а не прямой ссылкой на диск: публичный URL
        // раздал бы документы партнёра в обход скоупа.
        Route::get('/attachments/{media}/download', [AttachmentController::class, 'download'])
            ->name('attachments.download')
            ->whereNumber('media');
        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::delete('/attachments/{media}', [AttachmentController::class, 'destroy'])
            ->name('attachments.destroy')
            ->whereNumber('media');
    });

    // Планы продаж. Ввод — массовый: сетка отправляется строками «цель → сумма»,
    // поэтому отдельного PATCH на один план нет.
    Route::middleware('permission:crm-plans.view')->group(function () {
        Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('/plans/data', [PlanController::class, 'data'])->name('plans.data');

        // Выполнение планов (crm-06). Отдельные адреса, а не один жирный payload:
        // сводка нужна сразу, burndown и разрез по менеджерам — только на вкладке
        // «Выполнение», и грузить их при вводе планов незачем.
        Route::get('/plans/progress', [PlanController::class, 'progressData'])->name('plans.progress');
        Route::get('/plans/burndown', [PlanController::class, 'burndown'])->name('plans.burndown');
        Route::get('/plans/export', [PlanController::class, 'export'])->name('plans.export');

        // Разрез по менеджерам закрыт правом на весь отдел: менеджер не должен
        // видеть выручку соседа даже зная адрес.
        Route::get('/plans/by-manager', [PlanController::class, 'byManager'])
            ->middleware('permission:crm-clients-all.view')
            ->name('plans.by-manager');
    });

    // Возможности (crm-07): витрина поверх планов и gap-анализа. Своего права
    // на редактирование нет — список ничего не меняет, он предлагает.
    Route::middleware('permission:crm-opportunities.view')->group(function () {
        Route::get('/opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
        Route::get('/opportunities/data', [OpportunityController::class, 'data'])->name('opportunities.data');
        Route::get('/opportunities/dimensions', [OpportunityController::class, 'dimensions'])
            ->name('opportunities.dimensions');
        Route::get('/opportunities/export', [OpportunityController::class, 'export'])->name('opportunities.export');
    });

    // Грядки (crm-08): тот же план и те же сигналы, что на «Планах» и
    // «Возможностях», но одной картинкой. Своих расчётов раздел не добавляет.
    Route::middleware('permission:crm-beds.view')->group(function () {
        Route::get('/beds', [BedsController::class, 'index'])->name('beds.index');
        // До /beds/{client}: иначе «data» ушло бы в биндинг партнёра.
        Route::get('/beds/data', [BedsController::class, 'data'])->name('beds.data');
        Route::get('/beds/{client}/details', [BedsController::class, 'details'])
            ->name('beds.details')
            ->whereNumber('client');
    });

    Route::middleware('permission:crm-plans.edit')->group(function () {
        Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
        Route::post('/plans/copy-previous', [PlanController::class, 'copyPrevious'])
            ->name('plans.copy-previous');
    });

    Route::middleware('permission:crm-plans.delete')->group(function () {
        Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])
            ->name('plans.destroy')
            ->whereNumber('plan');
    });

    Route::middleware('permission:crm-team.view')->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
    });

    // Скрыть нерабочую карточку менеджера. Не удаление: карточку с erp_uuid
    // следующий обмен создаст заново, а её партнёры остались бы без менеджера.
    Route::middleware('permission:crm-team.edit')->group(function () {
        Route::put('/team/{manager}/active', [TeamController::class, 'setActive'])
            ->name('team.active')
            ->whereNumber('manager');
    });

    // Токены ИИ-агентов (crm-13). Только у РОПа: токен даёт запись в CRM
    // от имени сотрудника, и выдавать его себе сотрудник не должен.
    Route::middleware('permission:crm-agent-tokens.view')->group(function () {
        Route::get('/agent-tokens', [AgentTokenController::class, 'index'])->name('agent-tokens.index');
    });

    Route::middleware('permission:crm-agent-tokens.create')->group(function () {
        Route::post('/agent-tokens', [AgentTokenController::class, 'store'])->name('agent-tokens.store');
    });

    Route::middleware('permission:crm-agent-tokens.delete')->group(function () {
        Route::delete('/agent-tokens/{token}', [AgentTokenController::class, 'destroy'])
            ->name('agent-tokens.destroy')
            ->whereNumber('token');
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

    // Финансы: план поступлений, просрочка, балансы. Раздел только читает деньги,
    // прилетевшие из 1С, поэтому право одно — view. Скоуп партнёров тот же, что
    // у журналов документов: менеджер видит деньги только своих.
    Route::middleware('permission:crm-finance.view')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/data', [FinanceController::class, 'data'])->name('finance.data');
        // Выгрузка объявлена до остальных подстраниц не ради биндинга (его тут нет),
        // а чтобы не потеряться среди них при чтении файла.
        Route::get('/finance/export', [FinanceController::class, 'export'])->name('finance.export');
        Route::get('/finance/plan', [FinanceController::class, 'plan'])->name('finance.plan');
        Route::get('/finance/overdue', [FinanceController::class, 'overdue'])->name('finance.overdue');
        Route::get('/finance/balances', [FinanceController::class, 'balances'])->name('finance.balances');
        // Выгрузка объявлена до самой страницы: иначе `/reconciliation/export`
        // рискует быть съеденной параметром, если у страницы появится сегмент.
        Route::get('/finance/reconciliation/export', [FinanceController::class, 'reconciliationExport'])->name('finance.reconciliation.export');
        Route::get('/finance/reconciliation', [FinanceController::class, 'reconciliation'])->name('finance.reconciliation');
    });

    // Недоборы: подборки замен по отменам строк 1С. view — очередь и карточка,
    // edit — кандидаты, письмо и исходы. Поиск товара — свой роут под правом
    // раздела (crm.products.search закрыт правом аналитики, которого у рядового
    // менеджера может не быть). Статические сегменты — до /{offer}.
    Route::middleware('permission:crm-shortages.view')->group(function () {
        Route::get('/shortages/products/search', [ShortageController::class, 'searchProducts'])->name('shortages.products.search');
        Route::get('/shortages/links', [ShortageController::class, 'links'])->name('shortages.links');
        Route::get('/shortages', [ShortageController::class, 'index'])->name('shortages.index');
        Route::get('/shortages/{offer}', [ShortageController::class, 'show'])->name('shortages.show')->whereNumber('offer');
    });
    Route::middleware('permission:crm-shortages.edit')->group(function () {
        Route::post('/shortages/links/{link}/approve', [ShortageController::class, 'approveLink'])->name('shortages.links.approve')->whereNumber('link');
        Route::post('/shortages/links/{link}/reject', [ShortageController::class, 'rejectLink'])->name('shortages.links.reject')->whereNumber('link');
        Route::post('/shortages/{offer}/candidates', [ShortageController::class, 'storeCandidate'])->name('shortages.candidates.store')->whereNumber('offer');
        Route::delete('/shortages/{offer}/candidates/{item}', [ShortageController::class, 'removeCandidate'])->name('shortages.candidates.remove')->whereNumber('offer')->whereNumber('item');
        Route::post('/shortages/{offer}/candidates/{item}/restore', [ShortageController::class, 'restoreCandidate'])->name('shortages.candidates.restore')->whereNumber('offer')->whereNumber('item');
        Route::post('/shortages/{offer}/outcome', [ShortageController::class, 'outcome'])->name('shortages.outcome')->whereNumber('offer');
    });
});
