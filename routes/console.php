<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('currency:update')->daily();
Schedule::command('app:clean-price-dumps')->dailyAt('04:00');

// Регенерация кэшей стандартных выгрузок каждые 4 часа
Schedule::job(new \App\Jobs\RegeneratePresetExportsJob)->everyFourHours();
