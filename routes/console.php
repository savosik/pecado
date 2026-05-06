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
Schedule::command('exports:warm')->hourly()->withoutOverlapping(); // прогрев кэша стандартных пресетных выгрузок
