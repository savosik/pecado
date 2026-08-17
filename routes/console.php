<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('currency:update')->daily();
Schedule::command('app:clean-price-dumps')->dailyAt('04:00');
// Печатные формы документов (v16.1.0): доклейка связей у форм, приехавших раньше
// контрагента или реализации; перезапуск зависших переносов; уборка обменного
// бакета и файлов отозванных форм.
Schedule::command('documents:relink')->everyTenMinutes()->withoutOverlapping();
Schedule::command('documents:reconcile')->hourly()->withoutOverlapping();
Schedule::command('documents:clean-exchange')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('documents:prune')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('health:check')->everyMinute();
Schedule::command('search:sync')->cron('0 3 */3 * *'); // каждые 3 дня в 03:00
Schedule::command('search:repair-embeddings --reindex-missing')->dailyAt('03:20')->withoutOverlapping(); // досчёт векторов у товаров без эмбеддинга (упавших по 402/после сброса индекса)
Schedule::command('media:clean-temp')->hourly();
Schedule::command('exports:warm')->everyFifteenMinutes()->withoutOverlapping(); // прогрев кэша стандартных пресетных выгрузок
Schedule::command('exports:cleanup')->dailyAt('04:30')->withoutOverlapping(); // удаление orphaned/stale файлов кеша выгрузок
Schedule::command('stock:cleanup-availability')->dailyAt('04:50')->withoutOverlapping(); // журнал переходов доступности: ретенция STOCK_AVAILABILITY_RETENTION_DAYS
Schedule::command('stock:buffers:recompute')->dailyAt('02:20')->withoutOverlapping(); // страховой буфер по рисковым SKU (отмены/брак/срок годности); показ занижает только флаг STOCK_BUFFER_ENABLED (buf-04)
Schedule::command('erp:cleanup-messages')->dailyAt('05:00')->withoutOverlapping(); // лог шины ERP: архив в холодное хранилище + удаление старше ERP_BUS_RETENTION_DAYS
Schedule::command('erp:cleanup-processed')->dailyAt('05:20')->withoutOverlapping(); // журнал дедупликации входящих: ретенция ERP_PROCESSED_RETENTION_DAYS
Schedule::command('model:prune', ['--model' => [\App\Models\SentEmail::class]])->dailyAt('05:10'); // журнал исходящих писем: ретенция MAIL_JOURNAL_RETENTION_DAYS
Schedule::command('sitemap:generate')->dailyAt('03:30'); // после search:sync
Schedule::command('feed:build-yandex')->hourly()->withoutOverlapping(); // публичный YML-фид Яндекс.Маркета
Schedule::command('promo:rebuild-rule-products')->dailyAt('02:40')->withoutOverlapping(); // участники правил акций: состав категорий и теги меняются массово
Schedule::command('crm:lifecycle-hints')->dailyAt('06:10')->withoutOverlapping(); // подсказки по жизненному статусу клиентов — статусы НЕ меняет
Schedule::command('crm:back-in-stock-drafts')->dailyAt('07:20')->withoutOverlapping(); // черновики писем о вернувшихся в продажу товарах; ничего не отправляет
Schedule::command('crm:tasks-recur')->dailyAt('05:40')->withoutOverlapping(); // задачи по расписанию: материализация на сутки вперёд, до утренних напоминаний
Schedule::command('crm:tasks-remind')->dailyAt('08:30')->withoutOverlapping(); // напоминания о завтрашних дедлайнах и о просрочке за сутки (за флагом MAIL_FEATURE_CRM_TASKS)
Schedule::command('crm:tasks-push')->everyTenMinutes()->withoutOverlapping(); // push-напоминания подписанным браузерам (за флагом CRM_PUSH_ENABLED; без VAPID молчит)
Schedule::command('crm:tasks-weekly-report')->fridays()->at('17:00')->withoutOverlapping(); // недельный отчёт по задачам менеджерам и РОПу (за флагом MAIL_FEATURE_CRM_TASKS)
Schedule::command('crm:leads-remind-stale')->dailyAt('05:50')->withoutOverlapping(); // задачи по залежавшимся лидам; до материализации повторов и утренних напоминаний
Schedule::command('substitutions:follow-up')->hourly()->withoutOverlapping(); // дожим подборок замен: напоминание клиенту, задача «позвонить», просрочка (за флагом SHORTAGE_OFFERS_ENABLED)
Schedule::command('substitutions:premark')->weeklyOn(1, '06:30')->withoutOverlapping(); // ai-предразметка связей по дефицитному ядру — в очередь подтверждений, автоподбор их не использует до решения человека
Schedule::command('substitutions:purchasing-report')->weeklyOn(1, '07:00')->withoutOverlapping(); // отчёт закупкам о повторных недоборах — лечит запас, а не симптом (за флагом SHORTAGE_OFFERS_ENABLED)
Schedule::command('apiship:sync-statuses')->everyThirtyMinutes()->withoutOverlapping(); // страховка вебхука ORDER_STATUS: догоняет статусы, потерянные при недоступности сайта
Schedule::command('crm:plans-warm')->everyTenMinutes()->between('7:00', '21:00')->withoutOverlapping(); // прогрев тяжёлых агрегатов /crm/plans: первый утренний заход не ждёт пересчёт синхронно
