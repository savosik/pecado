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
Schedule::command('model:prune', ['--model' => [
    \App\Models\SentEmail::class,
    // Пульт уведомлений: сигналы — оперативная трасса (30 дней), доставки живут
    // дольше журнала писем (365): «когда мы перестали слать этому бухгалтеру»
    // спрашивают и через год, а строка компактная — тела письма в ней нет.
    \App\Models\NotificationSignal::class,
    \App\Models\NotificationDelivery::class,
]])->dailyAt('05:10'); // журнал исходящих писем: ретенция MAIL_JOURNAL_RETENTION_DAYS
Schedule::command('sitemap:generate')->dailyAt('03:30'); // после search:sync
Schedule::command('feed:build-yandex')->hourly()->withoutOverlapping(); // публичный YML-фид Яндекс.Маркета
Schedule::command('promo:rebuild-rule-products')->dailyAt('02:40')->withoutOverlapping(); // участники правил акций: состав категорий и теги меняются массово
Schedule::command('crm:lifecycle-hints')->dailyAt('06:10')->withoutOverlapping(); // подсказки по жизненному статусу клиентов — статусы НЕ меняет
Schedule::command('crm:back-in-stock-drafts')->dailyAt('07:20')->withoutOverlapping(); // черновики писем о вернувшихся в продажу товарах; ничего не отправляет
Schedule::command('crm:tasks-recur')->dailyAt('05:40')->withoutOverlapping(); // задачи по расписанию: материализация на сутки вперёд, до утренних напоминаний
// Финансовые поводы для пульта уведомлений: срок оплаты, просрочка, погашение.
// Плановый обход, а не реакция на balance.updated: 1С шлёт снимок баланса часто
// и не по порядку, и письмо на каждый пересчёт было бы шумом.
// Сведение накопленных уведомлений: серия правок заказа из 1С уходит одним
// письмом вместо десятка. Ежедневное — утром, чтобы не тревожить ночью.
Schedule::command('notifications:send-digests --period=hourly')->hourly()->withoutOverlapping();
Schedule::command('notifications:send-digests --period=daily')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('notifications:finance-scan')->dailyAt('07:00')->withoutOverlapping();
// Поток писем: непойманное правилами живёт MAIL_STREAM_UNMATCHED_DAYS и удаляется —
// иначе папка «Мимо фильтров» превратится в бесконечно растущую свалку.
Schedule::command('mail:prune-unmatched')->dailyAt('04:40')->withoutOverlapping();
Schedule::command('crm:tasks-remind')->dailyAt('08:30')->withoutOverlapping(); // напоминания о завтрашних дедлайнах и о просрочке за сутки (за флагом MAIL_FEATURE_CRM_TASKS)
Schedule::command('shortages:daily-notice')->weekdays()->at('17:00')->withoutOverlapping(); // вечерняя сводка неразнесённых недоборов менеджеру (за флагом MAIL_FEATURE_SHORTAGE_NOTICE); в выходные склад не собирает
Schedule::command('crm:tasks-push')->everyTenMinutes()->withoutOverlapping(); // push-напоминания подписанным браузерам (за флагом CRM_PUSH_ENABLED; без VAPID молчит)
Schedule::command('crm:tasks-weekly-report')->fridays()->at('17:00')->withoutOverlapping(); // недельный отчёт по задачам менеджерам и РОПу (за флагом MAIL_FEATURE_CRM_TASKS)
Schedule::command('crm:leads-remind-stale')->dailyAt('05:50')->withoutOverlapping(); // задачи по залежавшимся лидам; до материализации повторов и утренних напоминаний
Schedule::command('apiship:sync-statuses')->everyThirtyMinutes()->withoutOverlapping(); // страховка вебхука ORDER_STATUS: догоняет статусы, потерянные при недоступности сайта
Schedule::command('crm:plans-warm')->everyTenMinutes()->between('7:00', '21:00')->withoutOverlapping(); // прогрев тяжёлых агрегатов /crm/plans: первый утренний заход не ждёт пересчёт синхронно
