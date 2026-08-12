<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('currency:update')->daily();
Schedule::command('app:clean-price-dumps')->dailyAt('04:00');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('health:check')->everyMinute();
Schedule::command('search:sync')->cron('0 3 */3 * *'); // каждые 3 дня в 03:00
Schedule::command('search:repair-embeddings --reindex-missing')->dailyAt('03:20')->withoutOverlapping(); // досчёт векторов у товаров без эмбеддинга (упавших по 402/после сброса индекса)
Schedule::command('media:clean-temp')->hourly();
Schedule::command('exports:warm')->everyFifteenMinutes()->withoutOverlapping(); // прогрев кэша стандартных пресетных выгрузок
Schedule::command('exports:cleanup')->dailyAt('04:30')->withoutOverlapping(); // удаление orphaned/stale файлов кеша выгрузок
Schedule::command('erp:cleanup-messages')->dailyAt('05:00')->withoutOverlapping(); // лог шины ERP: архив в холодное хранилище + удаление старше ERP_BUS_RETENTION_DAYS
Schedule::command('erp:cleanup-processed')->dailyAt('05:20')->withoutOverlapping(); // журнал дедупликации входящих: ретенция ERP_PROCESSED_RETENTION_DAYS
Schedule::command('model:prune', ['--model' => [\App\Models\SentEmail::class]])->dailyAt('05:10'); // журнал исходящих писем: ретенция MAIL_JOURNAL_RETENTION_DAYS
Schedule::command('sitemap:generate')->dailyAt('03:30'); // после search:sync
Schedule::command('feed:build-yandex')->hourly()->withoutOverlapping(); // публичный YML-фид Яндекс.Маркета
Schedule::command('promo:rebuild-rule-products')->dailyAt('02:40')->withoutOverlapping(); // участники правил акций: состав категорий и теги меняются массово
Schedule::command('crm:lifecycle-hints')->dailyAt('06:10')->withoutOverlapping(); // подсказки по жизненному статусу клиентов — статусы НЕ меняет
Schedule::command('crm:tasks-recur')->dailyAt('05:40')->withoutOverlapping(); // задачи по расписанию: материализация на сутки вперёд, до утренних напоминаний
Schedule::command('crm:tasks-remind')->dailyAt('08:30')->withoutOverlapping(); // напоминания о завтрашних дедлайнах и о просрочке за сутки (за флагом MAIL_FEATURE_CRM_TASKS)
Schedule::command('apiship:sync-statuses')->everyThirtyMinutes()->withoutOverlapping(); // страховка вебхука ORDER_STATUS: догоняет статусы, потерянные при недоступности сайта
