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
Schedule::command('media:clean-temp')->hourly();
Schedule::command('exports:warm')->everyFifteenMinutes()->withoutOverlapping(); // прогрев кэша стандартных пресетных выгрузок
Schedule::command('exports:cleanup')->dailyAt('04:30')->withoutOverlapping(); // удаление orphaned/stale файлов кеша выгрузок
Schedule::command('erp:cleanup-messages')->dailyAt('05:00'); // очистка лога шины ERP старше 30 дней
Schedule::command('sitemap:generate')->dailyAt('03:30'); // после search:sync
Schedule::command('feed:build-yandex')->hourly()->withoutOverlapping(); // публичный YML-фид Яндекс.Маркета
